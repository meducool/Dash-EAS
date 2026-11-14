<?php

namespace Tests\Feature;

use App\Domain\Webstore\WooCommerceClient;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierPriceFile;
use App\Models\SupplierPriceItem;
use App\Models\User;
use App\Models\WebstoreBatch;
use App\Models\WebstoreChange;
use App\Models\WebstoreProduct;
use App\Models\WebstoreSetting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Boutique en ligne — step 4: morning preparation (never publishes), status, history, lock. */
class WebstoreAutomationTest extends TestCase
{
    use DatabaseTransactions;

    private string $sku;

    private int $siteStatus = 200;

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
        Product::query()->where('is_active', true)->whereIn('type', ['tyre', 'part'])->update(['is_active' => false]);

        $this->app->instance(WooCommerceClient::class, new class extends WooCommerceClient
        {
            protected function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        });

        // One matched product whose supplier price differs from the site price.
        $this->sku = 'AU'.strtoupper(substr(uniqid(), -6));
        $product = Product::query()->create(['type' => 'tyre', 'reference' => $this->sku, 'is_active' => true]);
        $user = User::factory()->create();
        $supplier = Supplier::query()->create(['name' => 'F '.$this->sku, 'user_id' => $user->id]);
        $file = SupplierPriceFile::query()->create(['supplier_id' => $supplier->id, 'name' => 'Tarif', 'is_active' => true]);
        SupplierPriceItem::query()->create(['file_id' => $file->id, 'supplier_id' => $supplier->id, 'row_number' => 2, 'designation' => 'x', 'price' => 800, 'product_id' => $product->id]);

        Http::fake(function (Request $request) {
            if ($this->siteStatus !== 200) {
                return Http::response([], $this->siteStatus);
            }

            return Http::response([[
                'id' => 301, 'sku' => $this->sku, 'name' => 'Site', 'type' => 'simple', 'status' => 'publish',
                'price' => '900.00', 'regular_price' => '900.00', 'stock_status' => 'onbackorder', 'stock_quantity' => null,
                'images' => [['id' => 1]], 'date_modified_gmt' => '2026-11-10T08:00:00',
            ]], 200, ['X-WP-Total' => 1, 'X-WP-TotalPages' => 1]);
        });
    }

    private function configure(bool $auto = true): void
    {
        WebstoreSetting::current()->fill([
            'store_url' => 'https://boutique.example.com', 'consumer_key' => 'ck_a1', 'consumer_secret' => 'cs_b2',
            'margin_percent' => 20, 'auto_prepare' => $auto,
        ])->save();
    }

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create(['name' => 'Admin']);
        $user->givePermissionTo(['view webstore', 'manage webstore', 'approve webstore']);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_it_does_nothing_while_switched_off(): void
    {
        $this->configure(auto: false);

        $this->artisan('webstore:prepare')->expectsOutputToContain('[off]')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, WebstoreBatch::query()->count());
        $this->assertNull(WebstoreSetting::current()->last_auto_run_at);
    }

    public function test_the_morning_run_reads_the_site_and_prepares_a_batch_without_publishing(): void
    {
        $this->configure();

        $this->artisan('webstore:prepare')->expectsOutputToContain('[ok]')->assertSuccessful();

        $batch = WebstoreBatch::query()->sole();
        $this->assertSame('Automatique', $batch->created_by_name);
        $line = $batch->changes()->sole();
        $this->assertSame(WebstoreChange::PROPOSED, $line->status);
        $this->assertEqualsWithDelta(960, $line->proposed['price'], 0.001);
        // Only reads on the site.
        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');

        $settings = WebstoreSetting::current();
        $this->assertNotNull($settings->last_auto_run_at);
        $this->assertNotNull($settings->last_pull_at);
        $this->assertSame('ok', $settings->last_auto_result['status']);
        $this->assertSame(1, $settings->last_auto_result['counts']['update']);
    }

    public function test_approved_lines_waiting_for_publication_are_never_replaced(): void
    {
        $this->configure();
        $this->artisan('webstore:prepare')->assertSuccessful();
        $line = WebstoreChange::query()->sole();
        $line->update(['status' => WebstoreChange::APPROVED]);

        $this->artisan('webstore:prepare')->expectsOutputToContain('1 ligne(s) approuvée(s)')->assertSuccessful();

        $this->assertSame(1, WebstoreBatch::query()->count());
        $this->assertSame(WebstoreChange::APPROVED, $line->fresh()->status);
        $this->assertSame('skipped', WebstoreSetting::current()->last_auto_result['status']);
    }

    public function test_a_site_error_is_kept_for_the_banner(): void
    {
        $this->configure();
        $this->siteStatus = 401;

        $this->artisan('webstore:prepare')->assertFailed();

        $result = WebstoreSetting::current()->last_auto_result;
        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('Clés API refusées', $result['message']);
        $this->assertSame(0, WebstoreBatch::query()->count());
    }

    public function test_missing_settings_are_reported_not_crashed(): void
    {
        WebstoreSetting::current()->fill(['auto_prepare' => true])->save();

        $this->artisan('webstore:prepare')->expectsOutputToContain('[skipped]')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_status_history_and_prepare_now(): void
    {
        $this->configure(auto: false);
        $this->actingAsAdmin();

        $this->putJson('/api/webstore/settings', ['auto_prepare' => true])->assertOk()->assertJsonPath('auto_prepare', true);

        $this->postJson('/api/webstore/prepare')->assertOk()->assertJsonPath('status', 'ok');
        $this->postJson('/api/webstore/prepare')->assertOk(); // second batch: the first one is superseded
        $first = WebstoreBatch::query()->orderBy('id')->first();

        $this->getJson('/api/webstore/status')->assertOk()
            ->assertJsonPath('auto.enabled', true)
            ->assertJsonPath('auto.last_result.status', 'ok')
            ->assertJsonPath('batch.statuses.proposed', 1);

        $this->getJson('/api/webstore/batches')->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.1.id', $first->id)
            ->assertJsonPath('data.1.status', WebstoreBatch::SUPERSEDED)
            ->assertJsonPath('data.1.statuses.superseded', 1)
            ->assertJsonPath('data.0.created_by_name', 'Automatique');

        // An old batch can be read, not decided.
        $this->getJson('/api/webstore/changes?batch_id='.$first->id)->assertOk()
            ->assertJsonPath('batch.id', $first->id)
            ->assertJsonPath('batch.status', WebstoreBatch::SUPERSEDED);
    }

    public function test_preparing_now_needs_the_manage_permission(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('view webstore');
        Sanctum::actingAs($viewer);

        $this->postJson('/api/webstore/prepare')->assertForbidden();
        $this->getJson('/api/webstore/status')->assertOk();
    }

    public function test_one_operation_at_a_time(): void
    {
        $this->configure();
        $this->actingAsAdmin();
        $lock = Cache::lock('webstore:operation', 60);
        $this->assertTrue($lock->get());

        $this->postJson('/api/webstore/pull')->assertStatus(422)->assertJsonValidationErrors('operation');
        $this->postJson('/api/webstore/batches')->assertStatus(422)->assertJsonValidationErrors('operation');
        $this->artisan('webstore:prepare')->assertFailed();
        $this->assertStringContainsString('déjà en cours', WebstoreSetting::current()->last_auto_result['message']);

        $lock->release();
        $this->postJson('/api/webstore/pull')->assertOk();
    }
}
