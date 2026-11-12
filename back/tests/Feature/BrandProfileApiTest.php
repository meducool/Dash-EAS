<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\BrandProfile;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class BrandProfileApiTest extends TestCase
{
    use DatabaseTransactions;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['view brands', 'edit brands'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->brand = Brand::query()->create(['name' => 'BrandProfileTest-'.uniqid(), 'is_active' => true]);
    }

    private function actingWith(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        Sanctum::actingAs($user, [], 'web');

        return $user;
    }

    public function test_viewers_can_list_but_not_change_profiles(): void
    {
        $this->actingWith(['view brands']);
        BrandProfile::query()->create(['brand_id' => $this->brand->id, 'name' => 'PRIMACY 4']);

        $this->getJson("/api/brands/{$this->brand->id}/profiles")
            ->assertOk()
            ->assertJsonPath('0.name', 'PRIMACY 4');

        $this->postJson("/api/brands/{$this->brand->id}/profiles", ['name' => 'PILOT SPORT 5'])->assertForbidden();
    }

    public function test_create_trims_the_name_and_refuses_duplicates_in_the_same_brand(): void
    {
        $this->actingWith(['view brands', 'edit brands']);

        $this->postJson("/api/brands/{$this->brand->id}/profiles", ['name' => '  PILOT   SPORT 5 '])
            ->assertCreated()
            ->assertJsonPath('name', 'PILOT SPORT 5');

        $this->postJson("/api/brands/{$this->brand->id}/profiles", ['name' => 'PILOT SPORT 5'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $other = Brand::query()->create(['name' => 'OtherBrand-'.uniqid(), 'is_active' => true]);
        $this->postJson("/api/brands/{$other->id}/profiles", ['name' => 'PILOT SPORT 5'])->assertCreated();

        $this->postJson("/api/brands/{$this->brand->id}/profiles", ['name' => ''])->assertStatus(422);
    }

    public function test_list_counts_products_and_brand_list_counts_profiles(): void
    {
        $this->actingWith(['view brands']);
        BrandProfile::query()->create(['brand_id' => $this->brand->id, 'name' => 'PRIMACY 4']);
        Product::query()->create(['type' => 'tyre', 'brand_id' => $this->brand->id, 'profile' => 'PRIMACY 4', 'is_active' => true]);
        Product::query()->create(['type' => 'tyre', 'brand_id' => $this->brand->id, 'profile' => 'primacy 4 ', 'is_active' => true]);

        $this->getJson("/api/brands/{$this->brand->id}/profiles")->assertJsonPath('0.products_count', 2);

        $brands = collect($this->getJson('/api/brands?all=1')->assertOk()->json());
        $this->assertSame(1, $brands->firstWhere('id', $this->brand->id)['profiles_count']);
    }

    public function test_rename_updates_the_products_of_that_brand(): void
    {
        $this->actingWith(['view brands', 'edit brands']);
        $profile = BrandProfile::query()->create(['brand_id' => $this->brand->id, 'name' => 'PRIMACY4']);
        $mine = Product::query()->create(['type' => 'tyre', 'brand_id' => $this->brand->id, 'profile' => 'PRIMACY4', 'is_active' => true]);
        $other = Brand::query()->create(['name' => 'OtherBrand-'.uniqid(), 'is_active' => true]);
        $theirs = Product::query()->create(['type' => 'tyre', 'brand_id' => $other->id, 'profile' => 'PRIMACY4', 'is_active' => true]);

        $this->putJson("/api/brand-profiles/{$profile->id}", ['name' => 'PRIMACY 4'])
            ->assertOk()
            ->assertJsonPath('name', 'PRIMACY 4')
            ->assertJsonPath('products_count', 1);

        $this->assertSame('PRIMACY 4', $mine->fresh()->profile);
        $this->assertSame('PRIMACY4', $theirs->fresh()->profile);
    }

    public function test_delete_keeps_the_product_profile_text(): void
    {
        $this->actingWith(['view brands', 'edit brands']);
        $profile = BrandProfile::query()->create(['brand_id' => $this->brand->id, 'name' => 'ENERGY SAVER']);
        $product = Product::query()->create(['type' => 'tyre', 'brand_id' => $this->brand->id, 'profile' => 'ENERGY SAVER', 'is_active' => true]);

        $this->deleteJson("/api/brand-profiles/{$profile->id}")->assertNoContent();

        $this->assertDatabaseMissing('brand_profiles', ['id' => $profile->id]);
        $this->assertSame('ENERGY SAVER', $product->fresh()->profile);
    }
}
