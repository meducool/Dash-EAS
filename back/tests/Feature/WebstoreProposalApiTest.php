<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Stock;
use App\Models\Supplier;
use App\Models\SupplierPriceFile;
use App\Models\SupplierPriceItem;
use App\Models\User;
use App\Models\WebstoreBatch;
use App\Models\WebstoreChange;
use App\Models\WebstoreProduct;
use App\Models\WebstoreSetting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Boutique en ligne — step 2: proposals computed from the POS, approved by hand. */
class WebstoreProposalApiTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private string $suffix;

    private Supplier $supplier;

    private SupplierPriceFile $file;

    /** @var array<string, Product> */
    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['view webstore', 'manage webstore', 'approve webstore'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        WebstoreChange::query()->delete();
        WebstoreBatch::query()->delete();
        WebstoreProduct::query()->delete();
        WebstoreSetting::query()->delete();
        // Only this test's products may be "absent from the site".
        Product::query()->where('is_active', true)->whereIn('type', ['tyre', 'part'])->update(['is_active' => false]);

        $this->admin = User::factory()->create(['name' => 'Admin Boutique']);
        $this->admin->givePermissionTo(['view webstore', 'manage webstore', 'approve webstore']);
        Sanctum::actingAs($this->admin);

        $this->suffix = strtoupper(substr(uniqid(), -6));
        $this->supplier = Supplier::query()->create(['name' => 'FOURN '.$this->suffix, 'user_id' => $this->admin->id]);
        $this->file = SupplierPriceFile::query()->create(['supplier_id' => $this->supplier->id, 'name' => 'Tarif', 'is_active' => true]);
        WebstoreSetting::current()->fill(['store_url' => 'https://boutique.example.com', 'margin_percent' => 20, 'last_pull_at' => now()])->save();

        // A: price, stock and photos to update.
        $this->p['A'] = $this->product('A', offer: 800, stock: 6, photos: 1);
        $this->site('A', 101, ['regular_price' => 900, 'stock_status' => 'instock', 'stock_quantity' => 4, 'images_count' => 0]);
        // B: price jump, below purchase cost, draft on the site (site has photos).
        $this->p['B'] = $this->product('B', offer: 500, cost: 700);
        $this->site('B', 102, ['regular_price' => 1000, 'status' => 'draft', 'images_count' => 2, 'stock_status' => 'onbackorder', 'stock_quantity' => 0]);
        // C: already up to date.
        $this->p['C'] = $this->product('C', offer: 500);
        $this->site('C', 103, ['regular_price' => 600, 'stock_status' => 'onbackorder', 'stock_quantity' => 0, 'images_count' => 1]);
        // D: absent from the site, with an offer → creation.
        $this->p['D'] = $this->product('D', offer: 400);
        // E: absent from the site, no offer → no price, skipped.
        $this->p['E'] = $this->product('E');
        // V: variable product on the site → skipped.
        $this->p['V'] = $this->product('V', offer: 300);
        $this->site('V', 105, ['type' => 'variable']);
    }

    private function product(string $key, ?float $offer = null, int $stock = 0, int $photos = 0, ?float $cost = null): Product
    {
        $product = Product::query()->create(['type' => 'tyre', 'reference' => $key.'WS'.$this->suffix, 'profile' => 'Profil '.$key, 'is_active' => true]);
        if ($stock > 0) {
            Stock::query()->create(['product_id' => $product->id, 'quantity' => $stock]);
        }
        for ($i = 0; $i < $photos; $i++) {
            ProductImage::query()->create(['product_id' => $product->id, 'path' => "products/{$product->id}/p{$i}.jpg", 'position' => $i]);
        }
        if ($offer !== null) {
            SupplierPriceItem::query()->create(['file_id' => $this->file->id, 'supplier_id' => $this->supplier->id, 'row_number' => 2, 'designation' => $key, 'price' => $offer, 'product_id' => $product->id]);
        }
        if ($cost !== null) {
            $purchase = Purchase::query()->create([
                'date' => '2026-10-01', 'supplier_id' => $this->supplier->id, 'total_quantity' => 1, 'total_price' => $cost,
                'discount' => 0, 'net_amount' => $cost, 'status' => 'RECU', 'payment_status' => 'NON PAYE', 'created_by' => $this->admin->id,
            ]);
            PurchaseItem::query()->create(['purchase_id' => $purchase->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => $cost]);
        }

        return $product;
    }

    private function site(string $key, int $wooId, array $values): void
    {
        WebstoreProduct::query()->create($values + [
            'woo_id' => $wooId, 'sku' => $this->p[$key]->reference, 'normalized_sku' => $this->p[$key]->reference,
            'name' => 'Site '.$key, 'type' => 'simple', 'status' => 'publish', 'match_status' => WebstoreProduct::MATCHED,
            'product_id' => $this->p[$key]->id, 'images_count' => 0,
        ]);
    }

    private function line(string $key): WebstoreChange
    {
        return WebstoreChange::query()->where('product_id', $this->p[$key]->id)->latest('id')->firstOrFail();
    }

    public function test_generation_needs_a_margin_and_a_catalogue_read(): void
    {
        WebstoreSetting::current()->update(['margin_percent' => null]);
        $this->postJson('/api/webstore/batches')->assertStatus(422)->assertJsonValidationErrors('margin_percent');

        WebstoreSetting::current()->update(['margin_percent' => 20, 'last_pull_at' => null]);
        $this->postJson('/api/webstore/batches')->assertStatus(422)->assertJsonValidationErrors('catalogue');
    }

    public function test_proposals_follow_the_offer_the_stock_and_the_photos(): void
    {
        $this->postJson('/api/webstore/batches')->assertCreated()
            ->assertJsonPath('batch.margin_percent', 20)
            ->assertJsonPath('batch.counts.update', 2)
            ->assertJsonPath('batch.counts.create', 1)
            ->assertJsonPath('batch.counts.unchanged', 1)
            ->assertJsonPath('batch.counts.no_price_skipped', 1)
            ->assertJsonPath('batch.counts.variable_skipped', 1)
            ->assertJsonPath('batch.statuses.proposed', 3);

        $a = $this->line('A');
        $this->assertSame(WebstoreChange::UPDATE, $a->action);
        $this->assertSame(101, $a->woo_id);
        $this->assertSame(['price', 'stock', 'images'], $a->fields);
        $this->assertEqualsWithDelta(960, $a->proposed['price'], 0.001); // 800 × 1.20
        $this->assertSame('instock', $a->proposed['stock_status']);
        $this->assertSame(6, $a->proposed['stock_quantity']);
        $this->assertCount(1, $a->proposed['images']);
        $this->assertStringContainsString('/storage/products/'.$this->p['A']->id.'/p0.jpg', $a->proposed['images'][0]['url']);
        $this->assertSame([], $a->alerts);

        $b = $this->line('B');
        $this->assertSame(['price'], $b->fields); // backorder already right, site photos never replaced
        $this->assertEqualsWithDelta(600, $b->proposed['price'], 0.001);
        $this->assertEqualsCanonicalizing(['price_jump', 'below_cost', 'site_draft'], $b->alerts);

        $d = $this->line('D');
        $this->assertSame(WebstoreChange::CREATE, $d->action);
        $this->assertNull($d->woo_id);
        $this->assertSame($this->p['D']->reference, $d->proposed['sku']);
        $this->assertEqualsWithDelta(480, $d->proposed['price'], 0.001);
        $this->assertSame('onbackorder', $d->proposed['stock_status']);
        $this->assertSame(['no_photo'], $d->alerts);

        $this->assertFalse(WebstoreChange::query()->whereIn('product_id', [$this->p['C']->id, $this->p['E']->id, $this->p['V']->id])->exists());
    }

    public function test_lines_are_listed_with_filters(): void
    {
        $this->postJson('/api/webstore/batches')->assertCreated();

        $this->getJson('/api/webstore/changes')->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('batch.statuses.proposed', 3);
        $this->getJson('/api/webstore/changes?alert=none')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.product.reference', $this->p['A']->reference)
            ->assertJsonPath('data.0.woo.name', 'Site A')
            ->assertJsonPath('data.0.final_price', 960);
        $this->getJson('/api/webstore/changes?action=create')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/webstore/changes?alert=below_cost')->assertOk()->assertJsonPath('data.0.product.id', $this->p['B']->id);
        $this->getJson('/api/webstore/changes?alert=hack')->assertStatus(422);
    }

    public function test_approval_rejection_and_reset_are_logged(): void
    {
        $this->postJson('/api/webstore/batches')->assertCreated();

        // "Approve everything without alert" only takes A.
        $this->postJson('/api/webstore/changes/decide', ['decision' => 'approve', 'scope' => 'no_alerts'])->assertOk()
            ->assertJsonPath('updated', 1)
            ->assertJsonPath('batch.statuses.approved', 1);
        $a = $this->line('A');
        $this->assertSame(WebstoreChange::APPROVED, $a->status);
        $this->assertSame('Admin Boutique', $a->decided_by_name);
        $this->assertNotNull($a->decided_at);

        $b = $this->line('B');
        $this->postJson('/api/webstore/changes/decide', ['decision' => 'reject', 'ids' => [$b->id]])->assertOk()->assertJsonPath('updated', 1);
        $this->assertSame(WebstoreChange::REJECTED, $b->fresh()->status);

        $this->postJson('/api/webstore/changes/decide', ['decision' => 'reset', 'ids' => [$a->id, $b->id]])->assertOk()->assertJsonPath('updated', 2);
        $this->assertSame(WebstoreChange::PROPOSED, $a->fresh()->status);
        $this->assertNull($a->fresh()->decided_by);

        $this->assertSame(3, ActivityLog::query()->where('entity_type', 'boutique')->count());
        $this->postJson('/api/webstore/changes/decide', ['decision' => 'reject', 'scope' => 'no_alerts'])->assertStatus(422);
    }

    public function test_only_the_approver_decides(): void
    {
        $this->postJson('/api/webstore/batches')->assertCreated();
        $manager = User::factory()->create();
        $manager->givePermissionTo(['view webstore', 'manage webstore']);
        Sanctum::actingAs($manager);

        $this->postJson('/api/webstore/changes/decide', ['decision' => 'approve', 'scope' => 'no_alerts'])->assertForbidden();
        $this->putJson('/api/webstore/changes/'.$this->line('A')->id.'/price', ['price' => 999])->assertForbidden();
        $this->getJson('/api/webstore/changes')->assertOk();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo('view webstore');
        Sanctum::actingAs($viewer);
        $this->postJson('/api/webstore/batches')->assertForbidden();
    }

    public function test_a_manual_price_recomputes_the_alerts_and_needs_a_new_approval(): void
    {
        $this->postJson('/api/webstore/batches')->assertCreated();
        $b = $this->line('B');
        $this->postJson('/api/webstore/changes/decide', ['decision' => 'approve', 'ids' => [$b->id]])->assertOk();

        $this->putJson("/api/webstore/changes/{$b->id}/price", ['price' => 750])->assertOk()
            ->assertJsonPath('status', WebstoreChange::PROPOSED)
            ->assertJsonPath('final_price', 750);
        $b->refresh();
        $this->assertEqualsCanonicalizing(['price_jump', 'site_draft', 'manual_price'], $b->alerts); // 750 ≥ cost 700
        $this->assertNull($b->decided_at);

        // Back to the computed price.
        $this->putJson("/api/webstore/changes/{$b->id}/price", ['price' => null])->assertOk()->assertJsonPath('final_price', 600);
        $this->assertEqualsCanonicalizing(['price_jump', 'below_cost', 'site_draft'], $b->fresh()->alerts);

        $this->putJson("/api/webstore/changes/{$b->id}/price", ['price' => -5])->assertStatus(422);
    }

    public function test_a_new_batch_supersedes_the_undecided_and_approved_lines(): void
    {
        $this->postJson('/api/webstore/batches')->assertCreated();
        $first = WebstoreBatch::query()->latest('id')->first();
        $a = $this->line('A');
        $b = $this->line('B');
        $this->postJson('/api/webstore/changes/decide', ['decision' => 'approve', 'ids' => [$a->id]])->assertOk();
        $this->postJson('/api/webstore/changes/decide', ['decision' => 'reject', 'ids' => [$b->id]])->assertOk();

        $this->postJson('/api/webstore/batches')->assertCreated();

        $this->assertSame(WebstoreBatch::SUPERSEDED, $first->fresh()->status);
        $this->assertSame(WebstoreChange::SUPERSEDED, $a->fresh()->status);
        $this->assertSame(WebstoreChange::REJECTED, $b->fresh()->status); // decisions kept as history
        $this->assertSame(1, WebstoreBatch::query()->where('status', WebstoreBatch::OPEN)->count());

        // Old lines cannot be decided any more.
        $this->postJson('/api/webstore/changes/decide', ['decision' => 'approve', 'ids' => [$a->id]])->assertOk()->assertJsonPath('updated', 0);
        $this->putJson("/api/webstore/changes/{$a->id}/price", ['price' => 900])->assertStatus(422);
    }
}
