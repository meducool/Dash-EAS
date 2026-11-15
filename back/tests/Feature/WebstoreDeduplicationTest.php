<?php

namespace Tests\Feature;

use App\Domain\Products\ProductReferenceService;
use App\Domain\Webstore\WebstoreProposalService;
use App\Domain\Webstore\WooCommerceClient;
use App\Models\Brand;
use App\Models\BrandProfile;
use App\Models\Product;
use App\Models\ProductTyre;
use App\Models\Supplier;
use App\Models\SupplierPriceFile;
use App\Models\SupplierPriceItem;
use App\Models\User;
use App\Models\WebstoreBatch;
use App\Models\WebstoreChange;
use App\Models\WebstoreLink;
use App\Models\WebstoreProduct;
use App\Models\WebstoreSetting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Same product on the POS and the shop, never twice: links, suggestions, SKU correction. */
class WebstoreDeduplicationTest extends TestCase
{
    use DatabaseTransactions;

    private Product $tyre;

    /** @var array<int, array<string, mixed>> products served by the fake shop */
    private array $site = [];

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['view webstore', 'manage webstore', 'approve webstore'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        WebstoreChange::query()->delete();
        WebstoreBatch::query()->delete();
        WebstoreLink::query()->delete();
        WebstoreProduct::query()->delete();
        WebstoreSetting::query()->delete();
        Product::query()->where('is_active', true)->whereIn('type', ['tyre', 'part'])->update(['is_active' => false]);

        $admin = User::factory()->create(['name' => 'Admin']);
        $admin->givePermissionTo(['view webstore', 'manage webstore', 'approve webstore']);
        Sanctum::actingAs($admin);

        // A real catalogue tyre: Zeta (code ZT) Speedline 205/55R16 91V, reference built by the POS rule.
        $suffix = strtoupper(substr(uniqid(), -5));
        $brand = Brand::query()->create(['name' => 'Zeta'.$suffix, 'code' => 'Z'.$suffix, 'is_active' => true]);
        BrandProfile::query()->create(['brand_id' => $brand->id, 'name' => 'Speedline', 'code' => 'SPL']);
        $data = ['type' => 'tyre', 'brand_id' => $brand->id, 'profile' => 'Speedline', 'tire_width' => 205, 'tire_height' => 55,
            'tire_diameter' => 16, 'tire_load_index' => '91', 'tire_speed_index' => 'V'];
        $reference = app(ProductReferenceService::class)->baseReference($data);
        $this->tyre = Product::query()->create(['type' => 'tyre', 'reference' => $reference, 'brand_id' => $brand->id, 'profile' => 'Speedline', 'is_active' => true]);
        ProductTyre::query()->create(['product_id' => $this->tyre->id, 'tire_width' => 205, 'tire_height' => 55, 'tire_diameter' => 16, 'tire_load_index' => '91', 'tire_speed_index' => 'V']);

        // A supplier offer, so the tyre would be proposed in creation if nothing was found on the site.
        $supplier = Supplier::query()->create(['name' => 'F'.$suffix, 'user_id' => $admin->id]);
        $file = SupplierPriceFile::query()->create(['supplier_id' => $supplier->id, 'name' => 'Tarif', 'is_active' => true]);
        SupplierPriceItem::query()->create(['file_id' => $file->id, 'supplier_id' => $supplier->id, 'row_number' => 2, 'designation' => 'x', 'price' => 700, 'product_id' => $this->tyre->id]);

        WebstoreSetting::current()->fill(['store_url' => 'https://boutique.example.com', 'consumer_key' => 'ck_a1', 'consumer_secret' => 'cs_b2', 'margin_percent' => 20])->save();
        $this->app->instance(WooCommerceClient::class, new class extends WooCommerceClient
        {
            protected function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
        Http::fake(fn (Request $r) => Http::response(array_values($this->site), 200, ['X-WP-Total' => count($this->site), 'X-WP-TotalPages' => 1]));
    }

    private function siteProduct(int $id, ?string $sku, string $name): void
    {
        $this->site[$id] = ['id' => $id, 'sku' => $sku, 'name' => $name, 'type' => 'simple', 'status' => 'publish',
            'price' => '850.00', 'regular_price' => '850.00', 'stock_status' => 'onbackorder', 'stock_quantity' => null,
            'images' => [], 'date_modified_gmt' => '2026-11-10T08:00:00'];
    }

    private function brandName(): string
    {
        return $this->tyre->brand->name;
    }

    public function test_a_site_product_under_another_sku_is_recognised_and_never_created_again(): void
    {
        $this->siteProduct(501, 'ANCIEN-123', 'Pneu '.$this->brandName().' Speedline 205/55 R16 91V');

        $this->postJson('/api/webstore/pull')->assertOk()
            ->assertJsonPath('site_only', 1)
            ->assertJsonPath('suspected_duplicates', 1);

        $this->getJson('/api/webstore/report?status=site_only')->assertOk()
            ->assertJsonPath('data.0.woo.id', 501)
            ->assertJsonPath('data.0.suggestion.id', $this->tyre->id);
        $this->getJson('/api/webstore/report?status=pos_only&search='.$this->tyre->reference)->assertOk()
            ->assertJsonPath('data.0.product.id', $this->tyre->id)
            ->assertJsonPath('data.0.suspected_duplicate.woo_id', 501);

        $this->postJson('/api/webstore/batches')->assertCreated()
            ->assertJsonPath('batch.counts.create', 0)
            ->assertJsonPath('batch.counts.duplicate_skipped', 1);
    }

    public function test_associating_by_hand_proposes_the_sku_correction_and_survives_the_next_read(): void
    {
        $this->siteProduct(502, 'ancien-456', 'Article du site');
        $this->postJson('/api/webstore/pull')->assertOk();

        $this->postJson('/api/webstore/links', ['woo_id' => 502, 'product_id' => $this->tyre->id])->assertOk()
            ->assertJsonPath('summary.matched', 1)
            ->assertJsonPath('summary.sku_to_fix', 1);
        $this->assertDatabaseHas('webstore_links', ['woo_id' => 502, 'product_id' => $this->tyre->id, 'source' => WebstoreLink::MANUAL]);

        // The next read keeps the association even though the SKU still differs.
        $this->postJson('/api/webstore/pull')->assertOk()->assertJsonPath('matched', 1)->assertJsonPath('site_only', 0);
        $this->getJson('/api/webstore/report?status=matched')->assertOk()
            ->assertJsonPath('data.0.link', WebstoreLink::MANUAL)
            ->assertJsonPath('data.0.sku_mismatch', true);

        $this->postJson('/api/webstore/batches')->assertCreated()->assertJsonPath('batch.counts.create', 0);
        $line = WebstoreChange::query()->where('woo_id', 502)->sole();
        $this->assertContains('sku', $line->fields);
        $this->assertSame($this->tyre->reference, $line->proposed['sku']);
        $this->assertSame('ancien-456', $line->current['sku']);
    }

    public function test_a_reference_corrected_in_the_pos_updates_the_site_sku_instead_of_creating(): void
    {
        $this->siteProduct(503, $this->tyre->reference, 'Même SKU');
        $this->postJson('/api/webstore/pull')->assertOk()->assertJsonPath('matched', 1);
        $this->assertDatabaseHas('webstore_links', ['woo_id' => 503, 'product_id' => $this->tyre->id, 'source' => WebstoreLink::BY_SKU]);

        $this->tyre->update(['reference' => $this->tyre->reference.'-X']);
        $this->postJson('/api/webstore/pull')->assertOk()->assertJsonPath('matched', 1)->assertJsonPath('sku_to_fix', 1);

        $this->postJson('/api/webstore/batches')->assertCreated()->assertJsonPath('batch.counts.create', 0);
        $this->assertSame($this->tyre->reference, WebstoreChange::query()->where('woo_id', 503)->sole()->proposed['sku']);
    }

    public function test_the_same_article_twice_on_the_site_is_a_duplicate(): void
    {
        $this->siteProduct(504, $this->tyre->reference, 'Original');
        $this->postJson('/api/webstore/pull')->assertOk();
        // Someone creates a copy by hand with the same SKU in lower case: WooCommerce let it through.
        $this->siteProduct(505, strtolower($this->tyre->reference), 'Copie');

        $this->postJson('/api/webstore/pull')->assertOk()->assertJsonPath('matched', 1)->assertJsonPath('duplicate', 1);
        $this->assertSame(WebstoreProduct::MATCHED, WebstoreProduct::query()->where('woo_id', 504)->value('match_status'));
        $this->assertSame(WebstoreProduct::DUPLICATE, WebstoreProduct::query()->where('woo_id', 505)->value('match_status'));
    }

    public function test_an_article_cannot_be_linked_to_two_site_products(): void
    {
        $this->siteProduct(506, $this->tyre->reference, 'Déjà relié');
        $this->siteProduct(507, null, 'Sans SKU');
        $this->postJson('/api/webstore/pull')->assertOk();

        $this->postJson('/api/webstore/links', ['woo_id' => 507, 'product_id' => $this->tyre->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.product_id.0', fn ($m) => str_contains($m, 'déjà relié au produit du site « Déjà relié »'));
        $this->postJson('/api/webstore/links', ['woo_id' => 999, 'product_id' => $this->tyre->id])->assertStatus(422)->assertJsonValidationErrors('woo_id');
    }

    public function test_an_association_can_be_undone_and_is_reserved_to_the_shop_manager(): void
    {
        $this->siteProduct(508, null, 'Sans SKU');
        $this->postJson('/api/webstore/pull')->assertOk();
        $this->postJson('/api/webstore/links', ['woo_id' => 508, 'product_id' => $this->tyre->id])->assertOk();

        $this->deleteJson('/api/webstore/links/508')->assertOk()->assertJsonPath('summary.no_sku', 1);
        $this->assertDatabaseMissing('webstore_links', ['woo_id' => 508]);
        $this->deleteJson('/api/webstore/links/508')->assertStatus(422);

        $viewer = User::factory()->create();
        $viewer->givePermissionTo('view webstore');
        Sanctum::actingAs($viewer);
        $this->postJson('/api/webstore/links', ['woo_id' => 508, 'product_id' => $this->tyre->id])->assertForbidden();
    }

    public function test_a_new_shop_forgets_the_links(): void
    {
        $this->siteProduct(509, $this->tyre->reference, 'Relié');
        $this->postJson('/api/webstore/pull')->assertOk();
        $this->assertSame(1, WebstoreLink::query()->count());

        $this->putJson('/api/webstore/settings', ['store_url' => 'https://autre.example.com'])->assertOk();
        $this->assertSame(0, WebstoreLink::query()->count());
    }

    public function test_a_created_product_is_named_like_the_site_products(): void
    {
        $this->postJson('/api/webstore/pull')->assertOk(); // empty shop
        $this->postJson('/api/webstore/batches')->assertCreated()->assertJsonPath('batch.counts.create', 1);

        $line = WebstoreChange::query()->where('product_id', $this->tyre->id)->sole();
        $this->assertSame('Pneu '.$this->brandName().' Speedline 205/55 R16 91V', $line->proposed['name']);
    }

    public function test_upper_case_brands_are_written_like_the_site(): void
    {
        $brand = Brand::query()->create(['name' => 'BF GOODRICH'.substr(str_shuffle('ABCDEFGHJKLMNP'), 0, 3), 'is_active' => true]);
        $product = Product::query()->create(['type' => 'tyre', 'reference' => 'BFG'.uniqid(), 'brand_id' => $brand->id, 'profile' => 'All-Terrain T/A KO2', 'is_active' => true]);
        ProductTyre::query()->create(['product_id' => $product->id, 'tire_width' => 265, 'tire_height' => 70, 'tire_diameter' => 17,
            'tire_load_index' => '121', 'tire_speed_index' => 'S', 'tire_reinforced' => true]);

        $name = app(WebstoreProposalService::class)->siteName($product->fresh(), 'x');

        $this->assertStringStartsWith('Pneu BF Goodrich', $name);
        $this->assertStringEndsWith('All-Terrain T/A KO2 265/70 R17 121S', $name); // the model: no XL / RunFlat suffix
    }

    public function test_a_profile_typed_differently_is_still_recognised(): void
    {
        // "Speed Line" on the site, "Speedline" in the POS.
        $this->siteProduct(510, 'AUTRE-SKU', 'Pneu '.$this->brandName().' SPEED LINE 205/55 R16 91V');

        $this->postJson('/api/webstore/pull')->assertOk()->assertJsonPath('suspected_duplicates', 1);
        $this->assertSame($this->tyre->id, WebstoreProduct::query()->where('woo_id', 510)->value('suggested_product_id'));
    }

    public function test_existing_site_products_are_renamed_to_the_model(): void
    {
        $model = 'Pneu '.$this->brandName().' Speedline 205/55 R16 91V';
        $this->tyre->update(['profile' => 'SPEEDLINE']); // capitals in the POS: written « Speedline » on the site
        $this->siteProduct(530, $this->tyre->reference, 'PNEU '.$this->brandName().' 205/55R16 SPEEDLINE');
        $this->postJson('/api/webstore/pull')->assertOk();

        $this->postJson('/api/webstore/batches')->assertCreated();
        $line = WebstoreChange::query()->where('woo_id', 530)->sole();
        $this->assertContains('name', $line->fields);
        $this->assertSame($model, $line->proposed['name']);

        // Already named like the model: no rename.
        $this->site[530]['name'] = $model;
        $this->postJson('/api/webstore/pull')->assertOk();
        $this->postJson('/api/webstore/batches')->assertCreated();
        $this->assertNotContains('name', WebstoreChange::query()->where('woo_id', 530)->latest('id')->first()->fields);
    }
}
