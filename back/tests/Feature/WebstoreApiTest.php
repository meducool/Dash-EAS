<?php

namespace Tests\Feature;

use App\Domain\Webstore\WooCommerceClient;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Stock;
use App\Models\Supplier;
use App\Models\SupplierPriceFile;
use App\Models\SupplierPriceItem;
use App\Models\User;
use App\Models\WebstoreProduct;
use App\Models\WebstoreSetting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Boutique en ligne — step 1: settings, connection test, catalogue read and SKU report. */
class WebstoreApiTest extends TestCase
{
    use DatabaseTransactions;

    private const URL = 'https://boutique.example.com';

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['view webstore', 'manage webstore', 'approve webstore', 'view products'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        WebstoreProduct::query()->delete();
        WebstoreSetting::query()->delete();

        // Public host without real DNS lookups.
        $this->app->instance(WooCommerceClient::class, new class extends WooCommerceClient
        {
            protected function resolve(string $host): array
            {
                return $host === 'interne.example.com' ? ['192.168.1.10'] : ['93.184.216.34'];
            }
        });
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['view webstore', 'manage webstore']);
        Sanctum::actingAs($user);

        return $user;
    }

    private function configure(): void
    {
        WebstoreSetting::current()->fill([
            'store_url' => self::URL,
            'consumer_key' => 'ck_test1234',
            'consumer_secret' => 'cs_secret5678',
            'consumer_key_hint' => '1234',
        ])->save();
    }

    /** Pages served by the fake shop (by ?page=) and the status it answers. */
    private array $shopPages = [[]];

    private int $shopStatus = 200;

    /**
     * WooCommerce answering $pages (each a list of products) by ?page=.
     * Registered once: a second Http::fake() would only add stubs after the first.
     *
     * @param  array<int, array<int, array<string, mixed>>>  $pages
     */
    private function fakeShop(array $pages, int $status = 200): void
    {
        $this->shopPages = $pages;
        $this->shopStatus = $status;
    }

    private function registerShop(): void
    {
        Http::fake(function (Request $request) {
            if ($this->shopStatus !== 200) {
                return Http::response(['code' => 'woocommerce_rest_cannot_view'], $this->shopStatus);
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $page = (int) ($query['page'] ?? 1);
            $total = array_sum(array_map('count', $this->shopPages));

            return Http::response($this->shopPages[$page - 1] ?? [], 200, ['X-WP-Total' => $total, 'X-WP-TotalPages' => count($this->shopPages)]);
        });
    }

    private function woo(int $id, ?string $sku, array $extra = []): array
    {
        return $extra + [
            'id' => $id, 'sku' => $sku, 'name' => "Produit {$id}", 'type' => 'simple', 'status' => 'publish',
            'price' => '950.00', 'regular_price' => '950.00', 'sale_price' => '', 'stock_status' => 'instock',
            'stock_quantity' => 4, 'images' => [['id' => 1]], 'permalink' => "https://boutique.example.com/p/{$id}",
            'date_modified_gmt' => '2026-11-10T08:00:00',
        ];
    }

    public function test_routes_are_protected_by_their_permissions(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('view products');
        Sanctum::actingAs($user);

        $this->getJson('/api/webstore/report')->assertForbidden();
        $this->getJson('/api/webstore/settings')->assertForbidden();
        $this->postJson('/api/webstore/pull')->assertForbidden();

        $user->givePermissionTo('view webstore');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->getJson('/api/webstore/report')->assertOk();
        $this->putJson('/api/webstore/settings', ['store_url' => self::URL])->assertForbidden();
    }

    public function test_settings_keep_the_keys_encrypted_and_never_send_them_back(): void
    {
        $this->admin();

        $response = $this->putJson('/api/webstore/settings', [
            'store_url' => 'boutique.example.com/wp-json/wc/v3/',
            'consumer_key' => 'ck_abcdef9876',
            'consumer_secret' => 'cs_topsecret42',
            'margin_percent' => 18.5,
        ])->assertOk()
            ->assertJsonPath('store_url', self::URL)
            ->assertJsonPath('has_credentials', true)
            ->assertJsonPath('consumer_key_hint', '9876')
            ->assertJsonPath('margin_percent', 18.5);

        $this->assertStringNotContainsString('topsecret', $response->getContent());
        $this->assertStringNotContainsString('ck_abcdef', $response->getContent());
        $raw = DB::table('webstore_settings')->first();
        $this->assertStringNotContainsString('topsecret', $raw->consumer_secret);
        $this->assertSame('cs_topsecret42', WebstoreSetting::current()->consumer_secret);

        // Empty key / secret: the saved ones are kept.
        $this->putJson('/api/webstore/settings', ['consumer_key' => '', 'consumer_secret' => null])->assertOk()->assertJsonPath('has_credentials', true);
        $this->assertSame('ck_abcdef9876', WebstoreSetting::current()->consumer_key);

        $this->putJson('/api/webstore/settings', ['clear_credentials' => true])->assertOk()->assertJsonPath('has_credentials', false);
    }

    public function test_settings_refuse_bad_keys_plain_http_and_internal_hosts(): void
    {
        $this->admin();

        $this->putJson('/api/webstore/settings', ['consumer_key' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('consumer_key');
        $this->putJson('/api/webstore/settings', ['consumer_secret' => 'ck_wrong'])->assertStatus(422)->assertJsonValidationErrors('consumer_secret');
        foreach (['http://boutique.example.com', 'https://127.0.0.1', 'https://10.0.0.5', 'https://interne.example.com', 'ftp://boutique.example.com'] as $url) {
            $this->putJson('/api/webstore/settings', ['store_url' => $url])->assertStatus(422)->assertJsonValidationErrors('store_url');
        }
        $this->assertNull(WebstoreSetting::current()->store_url);
    }

    public function test_connection_test_uses_basic_auth_and_explains_errors(): void
    {
        $this->admin();
        $this->configure();
        $this->registerShop();
        $this->fakeShop(array_fill(0, 42, [['id' => 1]]));

        $this->postJson('/api/webstore/test')->assertOk()->assertJsonPath('ok', true)->assertJsonPath('products_total', 42);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Basic '.base64_encode('ck_test1234:cs_secret5678'))
            && str_starts_with($r->url(), self::URL.'/wp-json/wc/v3/products?'));

        $this->fakeShop([[]], 401);
        $this->postJson('/api/webstore/test')->assertStatus(422)->assertJsonPath('errors.store_url.0', fn ($m) => str_contains($m, 'Clés API refusées'));
    }

    public function test_redirects_are_not_followed(): void
    {
        $this->admin();
        $this->configure();
        Http::fake(['*' => Http::response('', 301, ['Location' => 'https://ailleurs.example.net/wp-json/wc/v3/products'])]);

        $this->postJson('/api/webstore/test')->assertStatus(422)->assertJsonPath('errors.store_url.0', fn ($m) => str_contains($m, 'redirige'));
        Http::assertSentCount(1);
    }

    public function test_connection_test_requires_settings(): void
    {
        $this->admin();
        Http::fake();

        $this->postJson('/api/webstore/test')->assertStatus(422)->assertJsonValidationErrors('store_url');
        Http::assertNothingSent();
    }

    public function test_pull_reads_every_page_and_matches_by_sku(): void
    {
        $this->admin();
        $this->configure();
        $suffix = strtoupper(substr(uniqid(), -6));
        $matched = Product::query()->create(['type' => 'tyre', 'reference' => "MT{$suffix}", 'is_active' => true]);
        $posOnly = Product::query()->create(['type' => 'part', 'reference' => "PO{$suffix}", 'is_active' => true]);
        $service = Product::query()->create(['type' => 'service', 'reference' => "SV{$suffix}", 'is_active' => true]);
        $inactive = Product::query()->create(['type' => 'tyre', 'reference' => "IN{$suffix}", 'is_active' => false]);

        Stock::query()->create(['product_id' => $matched->id, 'quantity' => 6]);
        ProductImage::query()->create(['product_id' => $matched->id, 'path' => 'products/x.jpg', 'position' => 0]);
        $supplier = Supplier::query()->create(['name' => 'FOURN '.$suffix, 'user_id' => User::factory()->create()->id]);
        $file = SupplierPriceFile::query()->create(['supplier_id' => $supplier->id, 'name' => 'Tarif', 'is_active' => true]);
        SupplierPriceItem::query()->create(['file_id' => $file->id, 'supplier_id' => $supplier->id, 'row_number' => 2, 'designation' => 'x', 'price' => 812.5, 'product_id' => $matched->id]);

        $this->registerShop();
        $this->fakeShop([
            [
                $this->woo(11, ' mt'.strtolower($suffix).' '), // lower case + spaces: still matched
                $this->woo(12, 'INCONNU-'.$suffix, ['permalink' => 'javascript:alert(1)']),
            ],
            [
                $this->woo(13, ''),
                $this->woo(14, 'DBL'.$suffix),
                $this->woo(15, 'dbl'.$suffix, ['type' => 'variable']),
            ],
        ]);

        $this->postJson('/api/webstore/pull')->assertOk()
            ->assertJsonPath('site_total', 5)
            ->assertJsonPath('matched', 1)
            ->assertJsonPath('site_only', 1)
            ->assertJsonPath('no_sku', 1)
            ->assertJsonPath('duplicate', 2)
            ->assertJsonPath('variable', 1);
        Http::assertSentCount(2);
        $this->assertNotNull(WebstoreSetting::current()->last_pull_at);

        $this->getJson('/api/webstore/report?status=matched')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.woo.id', 11)
            ->assertJsonPath('data.0.woo.price', 950)
            ->assertJsonPath('data.0.product.id', $matched->id)
            ->assertJsonPath('data.0.product.stock', 6)
            ->assertJsonPath('data.0.product.images_count', 1)
            ->assertJsonPath('data.0.product.offers_count', 1)
            ->assertJsonPath('data.0.product.best_offer_ttc', 812.5);

        $this->getJson('/api/webstore/report?status=site_only')->assertOk()
            ->assertJsonPath('data.0.woo.id', 12)
            ->assertJsonPath('data.0.woo.permalink', null)
            ->assertJsonPath('data.0.product', null);

        $posOnlyIds = collect($this->getJson('/api/webstore/report?status=pos_only&per_page=100&search='.$suffix)->assertOk()->json('data'))->pluck('product.id');
        $this->assertTrue($posOnlyIds->contains($posOnly->id));
        $this->assertFalse($posOnlyIds->contains($matched->id));
        $this->assertFalse($posOnlyIds->contains($service->id));
        $this->assertFalse($posOnlyIds->contains($inactive->id));

        // A second read replaces the copy: product 12 removed from the site.
        $this->fakeShop([[$this->woo(11, 'MT'.$suffix)]]);
        $this->postJson('/api/webstore/pull')->assertOk()->assertJsonPath('site_total', 1)->assertJsonPath('site_only', 0);
        $this->assertDatabaseMissing('webstore_products', ['woo_id' => 12]);
    }

    public function test_a_new_shop_address_forgets_the_previous_catalogue(): void
    {
        $this->admin();
        $this->configure();
        $this->registerShop();
        $this->fakeShop([[$this->woo(21, 'X1')]]);
        $this->postJson('/api/webstore/pull')->assertOk();

        $this->putJson('/api/webstore/settings', ['store_url' => self::URL.'/'])->assertOk(); // same address
        $this->assertSame(1, WebstoreProduct::query()->count());

        $this->putJson('/api/webstore/settings', ['store_url' => 'https://autre-boutique.example.com'])->assertOk()->assertJsonPath('last_pull_at', null);
        $this->assertSame(0, WebstoreProduct::query()->count());
    }

    public function test_report_rejects_unknown_status(): void
    {
        $this->admin();

        $this->getJson('/api/webstore/report?status=hack')->assertStatus(422)->assertJsonValidationErrors('status');
    }
}
