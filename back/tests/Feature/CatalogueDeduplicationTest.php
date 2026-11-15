<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\BrandProfile;
use App\Models\Product;
use App\Models\ProductTyre;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Same tyre never created twice: existing tyre in the preview, merge of profiles written several ways. */
class CatalogueDeduplicationTest extends TestCase
{
    use DatabaseTransactions;

    private Brand $brand;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['view brands', 'edit brands', 'view products', 'create products'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $this->user = User::factory()->create(['name' => 'Admin Catalogue']);
        $this->user->givePermissionTo(['view brands', 'edit brands', 'view products', 'create products']);
        Sanctum::actingAs($this->user);

        $this->brand = Brand::query()->create(['name' => 'Dedup'.strtoupper(substr(uniqid(), -5)), 'code' => 'DD', 'is_active' => true]);
    }

    private function tyre(string $profile, string $load = '82', string $speed = 'T', bool $runflat = false, int $stock = 0): Product
    {
        $product = Product::query()->create(['type' => 'tyre', 'reference' => 'DD'.uniqid(), 'brand_id' => $this->brand->id, 'profile' => $profile, 'is_active' => true]);
        ProductTyre::query()->create(['product_id' => $product->id, 'tire_width' => 175, 'tire_height' => 65, 'tire_diameter' => 14,
            'tire_load_index' => $load, 'tire_speed_index' => $speed, 'tire_runflat' => $runflat]);
        if ($stock) {
            Stock::query()->create(['product_id' => $product->id, 'quantity' => $stock]);
        }

        return $product;
    }

    private function preview(array $params): TestResponse
    {
        return $this->getJson('/api/products-reference-preview?'.http_build_query($params + [
            'type' => 'tyre', 'brand_id' => $this->brand->id, 'tire_width' => 175, 'tire_height' => 65, 'tire_diameter' => 14,
            'tire_load_index' => '82', 'tire_speed_index' => 'T',
        ]));
    }

    public function test_the_preview_lists_the_same_tyre_already_in_the_catalogue(): void
    {
        $existing = $this->tyre('EcoContact 6', stock: 8);
        $this->tyre('EcoContact 6', speed: 'H');          // other speed index
        $this->tyre('EcoContact 6', runflat: true);       // runflat
        $this->tyre('PremiumContact 6');                  // other profile

        // "ECOCONTACT6" = "EcoContact 6" (case, spaces).
        $this->preview(['profile' => 'ECOCONTACT6'])->assertOk()
            ->assertJsonCount(1, 'existing')
            ->assertJsonPath('existing.0.id', $existing->id)
            ->assertJsonPath('existing.0.reference', $existing->reference)
            ->assertJsonPath('existing.0.stock', 8);

        $this->preview(['profile' => 'Energy Saver'])->assertOk()->assertJsonCount(0, 'existing');
        $this->getJson('/api/products-reference-preview?type=part&profile=x')->assertOk()->assertJsonCount(0, 'existing');
    }

    public function test_profiles_written_several_ways_are_listed_and_merged(): void
    {
        $kept = BrandProfile::query()->create(['brand_id' => $this->brand->id, 'name' => 'EcoContact 6']);
        $other = BrandProfile::query()->create(['brand_id' => $this->brand->id, 'name' => 'ECOCONTACT6', 'code' => 'EC6']);
        BrandProfile::query()->create(['brand_id' => $this->brand->id, 'name' => 'PremiumContact 6']);
        $a = $this->tyre('EcoContact 6');
        $b = $this->tyre('ECOCONTACT6');
        $reference = $b->reference;

        $group = collect($this->getJson('/api/brand-profiles-duplicates')->assertOk()->json('data'))->firstWhere('brand_id', $this->brand->id);
        $this->assertNotNull($group);
        $this->assertEqualsCanonicalizing(['EcoContact 6', 'ECOCONTACT6'], array_column($group['profiles'], 'name'));

        $this->postJson("/api/brand-profiles/{$other->id}/merge", ['into_id' => $kept->id])->assertOk()
            ->assertJsonPath('name', 'EcoContact 6')
            ->assertJsonPath('code', 'EC6')          // the kept profile had no code: it takes the merged one's
            ->assertJsonPath('products_count', 2);

        $this->assertDatabaseMissing('brand_profiles', ['id' => $other->id]);
        $this->assertSame('EcoContact 6', $b->fresh()->profile);
        $this->assertSame($reference, $b->fresh()->reference); // references are locked
        $this->assertSame('EcoContact 6', $a->fresh()->profile);
        $this->assertTrue(ActivityLog::query()->where('description', 'like', '%« ECOCONTACT6 » fusionné dans « EcoContact 6 »%')->exists());

        $this->assertNull(collect($this->getJson('/api/brand-profiles-duplicates')->json('data'))->firstWhere('brand_id', $this->brand->id));
    }

    public function test_a_merge_stays_within_one_brand_and_needs_edit_rights(): void
    {
        $profile = BrandProfile::query()->create(['brand_id' => $this->brand->id, 'name' => 'Turanza 6']);
        $otherBrand = Brand::query()->create(['name' => 'Autre'.uniqid(), 'is_active' => true]);
        $foreign = BrandProfile::query()->create(['brand_id' => $otherBrand->id, 'name' => 'TURANZA6']);

        $this->postJson("/api/brand-profiles/{$foreign->id}/merge", ['into_id' => $profile->id])->assertStatus(422)->assertJsonValidationErrors('into_id');
        $this->postJson("/api/brand-profiles/{$profile->id}/merge", ['into_id' => $profile->id])->assertStatus(422);

        $viewer = User::factory()->create();
        $viewer->givePermissionTo('view brands');
        Sanctum::actingAs($viewer);
        $this->getJson('/api/brand-profiles-duplicates')->assertOk();
        $this->postJson("/api/brand-profiles/{$foreign->id}/merge", ['into_id' => $profile->id])->assertForbidden();
    }
}
