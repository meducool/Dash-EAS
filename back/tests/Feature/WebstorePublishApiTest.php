<?php

namespace Tests\Feature;

use App\Domain\Webstore\WooCommerceClient;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\User;
use App\Models\WebstoreBatch;
use App\Models\WebstoreChange;
use App\Models\WebstoreProduct;
use App\Models\WebstoreSetting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Boutique en ligne — step 3: publishing of the approved lines to WooCommerce. */
class WebstorePublishApiTest extends TestCase
{
    use DatabaseTransactions;

    private const READ_AT = '2026-11-10 08:00:00';

    private WebstoreBatch $batch;

    private User $admin;

    /** @var array<int, array<string, mixed>> products answered by GET products?include= */
    private array $site = [];

    private int $writeStatus = 200;

    /** @var array<int, array<string, mixed>> bodies of the POST products/batch requests */
    private array $written = [];

    /** @var array<string, WebstoreChange> */
    private array $c = [];

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

        $this->admin = User::factory()->create(['name' => 'Admin Boutique']);
        $this->admin->givePermissionTo(['view webstore', 'manage webstore', 'approve webstore']);
        Sanctum::actingAs($this->admin);

        WebstoreSetting::current()->fill([
            'store_url' => 'https://boutique.example.com', 'consumer_key' => 'ck_test1', 'consumer_secret' => 'cs_test2',
            'margin_percent' => 20, 'last_pull_at' => self::READ_AT,
        ])->save();

        $this->app->instance(WooCommerceClient::class, new class extends WooCommerceClient
        {
            protected function resolve(string $host): array
            {
                return ['93.184.216.34']; // public hosts only in these tests (localhost is refused before resolving)
            }
        });
        // The POS as seen from the internet: photo URLs the site can download.
        URL::forceRootUrl('https://pos.example.com');

        $this->batch = WebstoreBatch::query()->create(['status' => WebstoreBatch::OPEN, 'margin_percent' => 20, 'catalogue_read_at' => self::READ_AT]);
        $suffix = strtoupper(substr(uniqid(), -6));
        $product = fn (string $k) => Product::query()->create(['type' => 'tyre', 'reference' => $k.'PB'.$suffix, 'is_active' => true]);

        // U1: price + stock + photos, site unchanged since the read → published.
        $this->c['U1'] = $this->change($product('U1'), 201, ['price', 'stock', 'images'], [
            'price' => 960, 'stock_status' => 'instock', 'stock_quantity' => 6,
            'images' => [['id' => 1, 'url' => 'https://pos.example.com/storage/products/1/a.jpg']],
        ]);
        // U2: modified by hand on the site after the read → conflict.
        $this->c['U2'] = $this->change($product('U2'), 202, ['price'], ['price' => 500]);
        // U3: deleted from the site → failure.
        $this->c['U3'] = $this->change($product('U3'), 203, ['stock'], ['stock_status' => 'outofstock', 'stock_quantity' => 0]);
        // C1: creation → published as draft.
        $this->c['C1'] = $this->change($product('C1'), null, ['price', 'stock'], [
            'sku' => 'NEW'.$suffix, 'name' => 'Michelin Primacy 4 205/55R16 91V', 'brand' => 'Michelin',
            'attributes' => ['Largeur' => '205', 'Indice de vitesse' => 'V'], 'price' => 480,
            'stock_status' => 'onbackorder', 'stock_quantity' => 0, 'images' => [],
        ]);
        // C2: refused by the site (SKU already used).
        $this->c['C2'] = $this->change($product('C2'), null, ['price', 'stock'], ['sku' => 'DUP'.$suffix, 'name' => 'Doublon', 'price' => 300, 'stock_status' => 'outofstock']);
        // P: not approved → never sent.
        $this->c['P'] = $this->change($product('P'), 204, ['price'], ['price' => 100], WebstoreChange::PROPOSED);

        foreach ([201, 202, 203, 204] as $id) {
            WebstoreProduct::query()->create([
                'woo_id' => $id, 'sku' => "S{$id}", 'normalized_sku' => "S{$id}", 'name' => "Site {$id}", 'type' => 'simple',
                'status' => 'publish', 'regular_price' => 900, 'price' => 900, 'match_status' => WebstoreProduct::MATCHED,
                'product_id' => $this->c[['U1', 'U2', 'U3', 'P'][$id - 201]]->product_id, 'woo_modified_at' => self::READ_AT,
            ]);
        }
        $this->site = [
            201 => ['id' => 201, 'date_modified_gmt' => '2026-11-10T08:00:00'],
            202 => ['id' => 202, 'date_modified_gmt' => '2026-11-12T15:30:00'],
            204 => ['id' => 204, 'date_modified_gmt' => '2026-11-10T08:00:00'],
        ];
        $this->fakeShop();
    }

    private function change(Product $product, ?int $wooId, array $fields, array $proposed, string $status = WebstoreChange::APPROVED): WebstoreChange
    {
        return WebstoreChange::query()->create([
            'batch_id' => $this->batch->id, 'product_id' => $product->id, 'woo_id' => $wooId,
            'action' => $wooId ? WebstoreChange::UPDATE : WebstoreChange::CREATE,
            'fields' => $fields, 'current' => ['price' => 900], 'proposed' => $proposed, 'alerts' => [], 'status' => $status,
        ]);
    }

    private function fakeShop(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'GET') {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $ids = array_map('intval', explode(',', (string) ($query['include'] ?? '')));

                return Http::response(array_values(array_intersect_key($this->site, array_flip($ids))));
            }
            $this->written[] = $request->data();
            if ($this->writeStatus !== 200) {
                return Http::response(['code' => 'woocommerce_rest_cannot_create'], $this->writeStatus);
            }
            $answer = ['create' => [], 'update' => []];
            foreach ($request->data()['create'] ?? [] as $i => $item) {
                $answer['create'][] = str_starts_with($item['sku'], 'DUP')
                    ? ['id' => 0, 'error' => ['code' => 'product_invalid_sku', 'message' => 'Le <b>UGS</b> est déjà utilisé.']]
                    : ['id' => 900 + $i, 'sku' => $item['sku'], 'name' => $item['name'], 'type' => 'simple', 'status' => $item['status'],
                        'regular_price' => $item['regular_price'], 'price' => $item['regular_price'], 'stock_status' => $item['stock_status'] ?? 'instock',
                        'stock_quantity' => null, 'images' => [], 'permalink' => 'https://boutique.example.com/p/new', 'date_modified_gmt' => '2026-11-14T12:00:00'];
            }
            foreach ($request->data()['update'] ?? [] as $item) {
                $answer['update'][] = ['id' => $item['id'], 'sku' => "S{$item['id']}", 'name' => "Site {$item['id']}", 'type' => 'simple', 'status' => 'publish',
                    'regular_price' => $item['regular_price'] ?? '900.00', 'price' => $item['regular_price'] ?? '900.00',
                    'stock_status' => 'instock', 'stock_quantity' => $item['stock_quantity'] ?? null,
                    'images' => $item['images'] ?? [], 'date_modified_gmt' => '2026-11-14T12:00:00'];
            }

            return Http::response($answer);
        });
    }

    public function test_publishes_the_approved_lines_and_refuses_the_risky_ones(): void
    {
        $this->postJson('/api/webstore/publish')->assertOk()
            ->assertJsonPath('published', 2)
            ->assertJsonPath('failed', 3)
            ->assertJsonPath('warnings', 0)
            ->assertJsonPath('stopped', null)
            ->assertJsonPath('batch.statuses.published', 2)
            ->assertJsonPath('batch.statuses.failed', 3)
            ->assertJsonPath('batch.statuses.proposed', 1);

        // One write request, holding only the safe lines.
        $this->assertCount(1, $this->written);
        $body = $this->written[0];
        $this->assertSame([201], array_column($body['update'], 'id'));
        $this->assertSame([
            'id' => 201, 'regular_price' => '960.00', 'sale_price' => '', 'manage_stock' => true, 'stock_quantity' => 6, 'backorders' => 'no',
            'images' => [['src' => 'https://pos.example.com/storage/products/1/a.jpg']],
        ], $body['update'][0]);
        $this->assertCount(2, $body['create']);
        $create = $body['create'][0];
        $this->assertSame('draft', $create['status']);
        $this->assertSame('480.00', $create['regular_price']);
        $this->assertFalse($create['manage_stock']);
        $this->assertSame('onbackorder', $create['stock_status']);
        $this->assertContains(['name' => 'Marque', 'options' => ['Michelin'], 'visible' => true], $create['attributes']);
        $this->assertArrayNotHasKey('images', $create);

        $u1 = $this->c['U1']->fresh();
        $this->assertSame(WebstoreChange::PUBLISHED, $u1->status);
        $this->assertNotNull($u1->published_at);
        $local = WebstoreProduct::query()->where('woo_id', 201)->first();
        $this->assertEqualsWithDelta(960, $local->regular_price, 0.001);
        $this->assertSame('2026-11-14 12:00:00', $local->woo_modified_at->format('Y-m-d H:i:s'));

        $c1 = $this->c['C1']->fresh();
        $this->assertSame(WebstoreChange::PUBLISHED, $c1->status);
        $this->assertSame(900, $c1->woo_id);
        $this->assertDatabaseHas('webstore_products', ['woo_id' => 900, 'product_id' => $c1->product_id, 'match_status' => WebstoreProduct::MATCHED]);
        // Linked at once: the next read will never propose it again in creation.
        $this->assertDatabaseHas('webstore_links', ['woo_id' => 900, 'product_id' => $c1->product_id, 'source' => 'created']);

        $this->assertStringContainsString('modifié sur le site', $this->c['U2']->fresh()->error);
        $this->assertStringContainsString('introuvable', $this->c['U3']->fresh()->error);
        $this->assertSame('Refusé par le site : Le UGS est déjà utilisé.', $this->c['C2']->fresh()->error);
        $this->assertSame(WebstoreChange::PROPOSED, $this->c['P']->fresh()->status);

        $this->assertNotNull(WebstoreSetting::current()->last_publish_at);
        $this->assertTrue(ActivityLog::query()->where('entity_type', 'boutique')->where('description', 'like', '%2 produit(s) publié(s), 3 échec(s)%')->exists());

        // A failed line can be approved again, then published on the next run.
        $this->postJson('/api/webstore/changes/decide', ['decision' => 'approve', 'ids' => [$this->c['C2']->id]])->assertOk()->assertJsonPath('updated', 1);
    }

    public function test_photos_the_site_cannot_download_are_left_out_with_a_warning(): void
    {
        URL::forceRootUrl('http://localhost:8888');

        $this->postJson('/api/webstore/publish')->assertOk()->assertJsonPath('warnings', 1);

        $this->assertArrayNotHasKey('images', $this->written[0]['update'][0]);
        $this->assertSame('960.00', $this->written[0]['update'][0]['regular_price']);
        $u1 = $this->c['U1']->fresh();
        $this->assertSame(WebstoreChange::PUBLISHED, $u1->status);
        $this->assertStringContainsString('Photos non envoyées', $u1->error);
    }

    public function test_keys_without_write_rights_stop_the_run(): void
    {
        $this->writeStatus = 401;

        $this->postJson('/api/webstore/publish')->assertOk()
            ->assertJsonPath('published', 0)
            ->assertJsonPath('stopped', fn ($m) => str_contains($m, 'Lecture/Écriture'));

        $this->assertSame(WebstoreChange::FAILED, $this->c['U1']->fresh()->status);
        $this->assertStringContainsString('Lecture/Écriture', $this->c['C1']->fresh()->error);
    }

    public function test_publishing_needs_approved_lines_and_the_approver(): void
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo(['view webstore', 'manage webstore']);
        Sanctum::actingAs($manager);
        $this->postJson('/api/webstore/publish')->assertForbidden();

        Sanctum::actingAs($this->admin);
        WebstoreChange::query()->where('status', WebstoreChange::APPROVED)->update(['status' => WebstoreChange::REJECTED]);
        $this->postJson('/api/webstore/publish')->assertStatus(422)->assertJsonValidationErrors('publish');
        Http::assertNothingSent();
    }

    public function test_the_new_products_status_is_a_setting(): void
    {
        $this->putJson('/api/webstore/settings', ['create_status' => 'publish'])->assertOk()->assertJsonPath('create_status', 'publish');
        $this->putJson('/api/webstore/settings', ['create_status' => 'trash'])->assertStatus(422);

        $this->postJson('/api/webstore/publish')->assertOk();
        $this->assertSame('publish', $this->written[0]['create'][0]['status']);
    }

    public function test_a_sku_correction_is_sent_with_the_update(): void
    {
        $this->c['U1']->update(['fields' => ['sku'], 'proposed' => ['sku' => 'REF-POS-1']]);
        WebstoreChange::query()->whereKeyNot($this->c['U1']->id)->update(['status' => WebstoreChange::REJECTED]);

        $this->postJson('/api/webstore/publish')->assertOk()->assertJsonPath('published', 1);
        $this->assertSame(['id' => 201, 'sku' => 'REF-POS-1'], $this->written[0]['update'][0]);
    }

    public function test_a_rename_is_sent_with_the_update(): void
    {
        $this->c['U1']->update(['fields' => ['name'], 'proposed' => ['name' => 'Pneu Pirelli Cinturato P7 Blue 215/50 R17 95W']]);
        WebstoreChange::query()->whereKeyNot($this->c['U1']->id)->update(['status' => WebstoreChange::REJECTED]);

        $this->postJson('/api/webstore/publish')->assertOk()->assertJsonPath('published', 1);
        $this->assertSame(['id' => 201, 'name' => 'Pneu Pirelli Cinturato P7 Blue 215/50 R17 95W'], $this->written[0]['update'][0]);
    }
}
