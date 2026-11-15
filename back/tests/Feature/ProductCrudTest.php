<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\BrandProfile;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProductCrudTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    private Brand $brand;

    private Product $tyreProduct;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Route::has('login')) {
            Route::get('/login', fn () => response()->json(['message' => 'Unauthenticated.'], 401))->name('login');
        }

        $this->ensureTablesExist();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['view products', 'create products', 'edit products', 'delete products'] as $perm) {
            Permission::findOrCreate($perm, 'web');
        }

        $admin = Role::findOrCreate('Administrator', 'web');
        $admin->syncPermissions(Permission::query()->get());

        $this->user = User::query()->create([
            'name' => 'Test Admin',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'phone' => '0600000000',
            'commission_rate' => 0,
            'must_change_password' => false,
        ]);
        $this->user->assignRole($admin);

        $this->brand = Brand::query()->create(['name' => 'TestBrand-'.uniqid(), 'is_active' => true]);

        $this->tyreProduct = Product::query()->create([
            'reference' => 'MIC-2055516-'.fake()->unique()->numerify('###'),
            'type' => 'tyre',
            'brand_id' => $this->brand->id,
            'is_active' => true,
        ]);

        DB::table('product_tyres')->insert([
            'product_id' => $this->tyreProduct->id,
            'tire_width' => 205,
            'tire_height' => 55,
            'tire_diameter' => 16,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function ensureTablesExist(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $t) {
                $t->id();
                $t->string('name');
                $t->string('email')->unique();
                $t->string('password');
                $t->string('phone')->nullable();
                $t->decimal('commission_rate', 5, 2)->default(0);
                $t->boolean('must_change_password')->default(false);
                $t->rememberToken();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('personal_access_tokens')) {
            Schema::create('personal_access_tokens', function (Blueprint $t) {
                $t->id();
                $t->morphs('tokenable');
                $t->string('name');
                $t->string('token', 64)->unique();
                $t->text('abilities')->nullable();
                $t->timestamp('last_used_at')->nullable();
                $t->timestamp('expires_at')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $t) {
                $t->id();
                $t->string('name');
                $t->string('guard_name');
                $t->timestamps();
                $t->unique(['name', 'guard_name']);
            });
        }

        if (! Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $t) {
                $t->id();
                $t->string('name');
                $t->string('guard_name');
                $t->timestamps();
                $t->unique(['name', 'guard_name']);
            });
        }

        if (! Schema::hasTable('role_has_permissions')) {
            Schema::create('role_has_permissions', function (Blueprint $t) {
                $t->unsignedBigInteger('permission_id');
                $t->unsignedBigInteger('role_id');
                $t->primary(['permission_id', 'role_id']);
            });
        }

        if (! Schema::hasTable('model_has_roles')) {
            Schema::create('model_has_roles', function (Blueprint $t) {
                $t->unsignedBigInteger('role_id');
                $t->string('model_type');
                $t->unsignedBigInteger('model_id');
                $t->index(['model_id', 'model_type']);
                $t->primary(['role_id', 'model_id', 'model_type']);
            });
        }

        if (! Schema::hasTable('model_has_permissions')) {
            Schema::create('model_has_permissions', function (Blueprint $t) {
                $t->unsignedBigInteger('permission_id');
                $t->string('model_type');
                $t->unsignedBigInteger('model_id');
                $t->index(['model_id', 'model_type']);
                $t->primary(['permission_id', 'model_id', 'model_type']);
            });
        }

        if (! Schema::hasTable('brands')) {
            Schema::create('brands', function (Blueprint $t) {
                $t->id();
                $t->string('name');
                $t->boolean('is_active')->default(true);
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('products')) {
            Schema::create('products', function (Blueprint $t) {
                $t->id();
                $t->string('reference')->nullable();
                $t->string('type', 20)->default('tyre');
                $t->string('profile')->nullable();
                $t->string('description')->nullable();
                $t->string('unit', 50)->nullable();
                $t->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
                $t->boolean('is_active')->default(true);
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('product_tyres')) {
            Schema::create('product_tyres', function (Blueprint $t) {
                $t->unsignedBigInteger('product_id')->primary();
                $t->smallInteger('tire_width')->nullable();
                $t->smallInteger('tire_height')->nullable();
                $t->smallInteger('tire_diameter')->nullable();
                $t->string('tire_load_index', 20)->nullable();
                $t->string('tire_speed_index', 10)->nullable();
                $t->string('tire_season', 20)->nullable();
                $t->boolean('tire_runflat')->default(false);
                $t->boolean('tire_reinforced')->default(false);
                $t->string('tire_marking')->nullable();
                $t->char('eu_fuel', 1)->nullable();
                $t->char('eu_wet_grip', 1)->nullable();
                $t->smallInteger('eu_noise_db')->nullable();
                $t->char('eu_noise_class', 1)->nullable();
                $t->timestamps();
                $t->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('product_parts')) {
            Schema::create('product_parts', function (Blueprint $t) {
                $t->unsignedBigInteger('product_id')->primary();
                $t->string('category', 50)->default('other');
                $t->string('oem_reference')->nullable();
                $t->text('compatibility')->nullable();
                $t->timestamps();
                $t->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('product_services')) {
            Schema::create('product_services', function (Blueprint $t) {
                $t->unsignedBigInteger('product_id')->primary();
                $t->string('category', 50)->default('other');
                $t->smallInteger('duration_minutes')->nullable();
                $t->decimal('selling_price', 10, 2)->nullable();
                $t->timestamps();
                $t->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('stocks')) {
            Schema::create('stocks', function (Blueprint $t) {
                $t->id();
                $t->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
                $t->string('made_in', 100)->nullable();
                $t->string('dot', 50)->nullable();
                $t->string('depot', 100)->nullable();
                $t->string('zone', 50)->nullable();
                $t->integer('quantity')->default(0);
                $t->decimal('purchase_price', 10, 2)->nullable();
                $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('stock_movements')) {
            Schema::create('stock_movements', function (Blueprint $t) {
                $t->id();
                $t->foreignId('stock_id')->nullable()->constrained()->nullOnDelete();
                $t->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
                $t->string('type', 32);
                $t->integer('quantity_before');
                $t->integer('quantity_after');
                $t->integer('delta');
                $t->string('reference_type')->nullable();
                $t->unsignedBigInteger('reference_id')->nullable();
                $t->text('reason')->nullable();
                $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $t->timestamps();
            });
        }
    }

    // -------------------------------------------------------------------------
    // GET /api/products
    // -------------------------------------------------------------------------

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/products')->assertUnauthorized();
    }

    public function test_index_requires_permission(): void
    {
        $noPerms = User::query()->create([
            'name' => 'No Perms',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'phone' => '0600000001',
            'commission_rate' => 0,
            'must_change_password' => false,
        ]);

        Sanctum::actingAs($noPerms, [], 'web');

        $this->getJson('/api/products')->assertForbidden();
    }

    public function test_index_returns_paginated_products(): void
    {
        Sanctum::actingAs($this->user, [], 'web');

        $response = $this->getJson('/api/products');

        $response->assertOk()
            ->assertJsonStructure(['data', 'total', 'current_page', 'per_page']);

        $this->assertGreaterThanOrEqual(1, $response->json('total'));
    }

    public function test_index_filters_by_type(): void
    {
        Sanctum::actingAs($this->user, [], 'web');

        $response = $this->getJson('/api/products?type=tyre');
        $response->assertOk();

        foreach ($response->json('data') as $item) {
            $this->assertEquals('tyre', $item['type']);
        }
    }

    public function test_index_filters_by_brand(): void
    {
        Sanctum::actingAs($this->user, [], 'web');

        $response = $this->getJson("/api/products?brand_id={$this->brand->id}");
        $response->assertOk();

        foreach ($response->json('data') as $item) {
            $this->assertEquals($this->brand->id, $item['brand_id']);
        }
    }

    // -------------------------------------------------------------------------
    // GET /api/products-filters
    // -------------------------------------------------------------------------

    public function test_filters_returns_options(): void
    {
        Sanctum::actingAs($this->user, [], 'web');

        $response = $this->getJson('/api/products-filters');

        $response->assertOk()
            ->assertJsonStructure(['brands', 'types', 'seasons']);

        $this->assertContains('tyre', $response->json('types'));
        $this->assertContains('part', $response->json('types'));
        $this->assertContains('service', $response->json('types'));
    }

    // -------------------------------------------------------------------------
    // POST /api/products
    // -------------------------------------------------------------------------

    public function test_store_requires_authentication(): void
    {
        $this->postJson('/api/products', [])->assertUnauthorized();
    }

    public function test_store_requires_permission(): void
    {
        $noPerms = User::query()->create([
            'name' => 'No Perms',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'phone' => '0600000002',
            'commission_rate' => 0,
            'must_change_password' => false,
        ]);

        Sanctum::actingAs($noPerms, [], 'web');

        $this->postJson('/api/products', ['type' => 'tyre'])->assertForbidden();
    }

    public function test_store_validates_required_type(): void
    {
        Sanctum::actingAs($this->user, [], 'web');

        $this->postJson('/api/products', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
    }

    public function test_store_validates_type_enum(): void
    {
        Sanctum::actingAs($this->user, [], 'web');

        $this->postJson('/api/products', ['type' => 'invalid'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
    }

    public function test_parts_and_services_keep_a_free_reference_tyres_ignore_it(): void
    {
        Sanctum::actingAs($this->user, [], 'web');
        $free = 'Castrol 5W30 LL '.uniqid();

        $this->postJson('/api/products', ['type' => 'part', 'reference' => "  {$free} "])
            ->assertCreated()
            ->assertJsonPath('reference', $free);
        // Still unique, whatever the case.
        $this->postJson('/api/products', ['type' => 'service', 'reference' => strtoupper($free)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reference');

        // Left empty: generated (P / S + id).
        $part = $this->postJson('/api/products', ['type' => 'part'])->assertCreated();
        $this->assertSame('P'.str_pad((string) $part->json('id'), 7, '0', STR_PAD_LEFT), $part->json('reference'));

        // Tyre: a typed reference is ignored.
        $tyre = $this->postJson('/api/products', ['type' => 'tyre', 'reference' => 'MA-REF-'.uniqid()])->assertCreated();
        $this->assertStringStartsNotWith('MA-REF-', $tyre->json('reference'));
    }

    public function test_store_generates_the_tyre_reference_from_brand_and_profile_codes(): void
    {
        Sanctum::actingAs($this->user, [], 'web');
        $brand = Brand::query()->create(['name' => 'GITI TEST '.uniqid(), 'code' => 'GZ', 'is_active' => true]);
        BrandProfile::query()->create(['brand_id' => $brand->id, 'name' => 'SYNERGY', 'code' => 'SYN']);
        $payload = [
            'type' => 'tyre',
            'brand_id' => $brand->id,
            'profile' => 'synergy',
            'tire_width' => 205,
            'tire_height' => 60,
            'tire_diameter' => 16,
            'tire_load_index' => '92',
            'tire_speed_index' => 'h',
        ];

        $this->getJson('/api/products-reference-preview?'.http_build_query($payload))
            ->assertOk()
            ->assertJsonPath('reference', 'GZ205601692H-SYN');

        $this->postJson('/api/products', $payload)->assertCreated()->assertJsonPath('reference', 'GZ205601692H-SYN');
        // Same tyre again: still unique.
        $this->postJson('/api/products', $payload)->assertCreated()->assertJsonPath('reference', 'GZ205601692H-SYN-2');

        // Run flat, no profile code yet → initials; service → S + id.
        $this->postJson('/api/products', array_merge($payload, ['profile' => 'Pilot Sport 4', 'tire_runflat' => true]))
            ->assertCreated()
            ->assertJsonPath('reference', 'GZ205601692HRF-PS4');
    }

    public function test_reference_is_locked_except_for_an_administrator(): void
    {
        $product = $this->tyreProduct;
        $original = $product->reference;
        $payload = ['type' => 'tyre', 'reference' => 'NEW-REF-'.uniqid(), 'brand_id' => $this->brand->id];

        $manager = User::factory()->create();
        $manager->givePermissionTo('edit products');
        Sanctum::actingAs($manager, [], 'web');

        $this->putJson("/api/products/{$product->id}", $payload)->assertStatus(422)->assertJsonValidationErrors('reference');
        // Sending the same reference (or none) is fine and keeps it.
        $this->putJson("/api/products/{$product->id}", array_merge($payload, ['reference' => $original]))->assertOk();
        $this->putJson("/api/products/{$product->id}", array_merge($payload, ['reference' => null]))
            ->assertOk()
            ->assertJsonPath('reference', $original);

        $this->app['auth']->forgetGuards(); // the guard keeps the first user between requests
        Sanctum::actingAs($this->user, [], 'web'); // Administrator
        $this->putJson("/api/products/{$product->id}", array_merge($payload, ['reference' => ' new ref x ']))
            ->assertOk()
            ->assertJsonPath('reference', 'NEWREFX');
        $this->assertDatabaseHas('activity_logs', ['entity_type' => 'produit', 'entity_id' => $product->id, 'action' => 'UPDATE']);
    }

    public function test_store_creates_the_first_stock_lot_with_location_dot_and_origin(): void
    {
        Sanctum::actingAs($this->user, [], 'web');

        $response = $this->postJson('/api/products', [
            'type' => 'part',
            'reference' => 'PART-'.fake()->unique()->numerify('######'),
            'stock' => ['depot' => ' ATELIER ', 'zone' => 'A1', 'dot' => '2426', 'made_in' => 'France'],
        ])->assertCreated();

        $this->assertDatabaseHas('stocks', [
            'product_id' => $response->json('id'),
            'quantity' => 0,
            'depot' => 'ATELIER',
            'zone' => 'A1',
            'dot' => '2426',
            'made_in' => 'France',
        ]);
    }

    public function test_store_validates_stock_lot_fields(): void
    {
        Sanctum::actingAs($this->user, [], 'web');

        $this->postJson('/api/products', [
            'type' => 'tyre',
            'stock' => ['zone' => str_repeat('Z', 51)],
        ])->assertStatus(422)->assertJsonValidationErrors('stock.zone');
    }

    public function test_store_creates_tyre_product_with_details_and_zero_stock(): void
    {
        Sanctum::actingAs($this->user, [], 'web');

        $response = $this->postJson('/api/products', [
            'type' => 'tyre',
            'brand_id' => $this->brand->id,
            'tire_width' => 225,
            'tire_height' => 45,
            'tire_diameter' => 17,
            'tire_season' => 'summer',
        ]);

        $response->assertCreated()->assertJsonPath('type', 'tyre');
        // Generated: brand code (first letter when none) + 225 45 17.
        $this->assertMatchesRegularExpression('/^T2254517(-\d+)?$/', $response->json('reference'));

        $productId = $response->json('id');

        $this->assertDatabaseHas('products', ['id' => $productId, 'type' => 'tyre']);
        $this->assertDatabaseHas('product_tyres', [
            'product_id' => $productId,
            'tire_width' => 225,
            'tire_height' => 45,
            'tire_diameter' => 17,
        ]);
        $this->assertDatabaseHas('stocks', [
            'product_id' => $productId,
            'quantity' => 0,
        ]);
    }

    public function test_store_creates_service_product_without_stock(): void
    {
        Sanctum::actingAs($this->user, [], 'web');

        $response = $this->postJson('/api/products', [
            'type' => 'service',
            'reference' => 'SVC-MONTAGE-'.fake()->unique()->numerify('###'),
            'service_category' => 'tires',
            'selling_price' => 150.00,
        ]);

        $response->assertCreated()
            ->assertJsonPath('type', 'service');

        $productId = $response->json('id');

        $this->assertDatabaseHas('product_services', ['product_id' => $productId]);
        $this->assertDatabaseMissing('stocks', ['product_id' => $productId]);
    }

    public function test_store_creates_part_product(): void
    {
        Sanctum::actingAs($this->user, [], 'web');

        $response = $this->postJson('/api/products', [
            'type' => 'part',
            'reference' => 'PART-BRAKE-'.fake()->unique()->numerify('###'),
            'part_category' => 'brakes',
            'oem_reference' => 'OEM-12345',
        ]);

        $response->assertCreated()
            ->assertJsonPath('type', 'part');

        $productId = $response->json('id');

        $this->assertDatabaseHas('product_parts', [
            'product_id' => $productId,
            'category' => 'brakes',
            'oem_reference' => 'OEM-12345',
        ]);
        $this->assertDatabaseHas('stocks', ['product_id' => $productId]);
    }

    // -------------------------------------------------------------------------
    // GET /api/products/{product}
    // -------------------------------------------------------------------------

    public function test_show_returns_product_with_relations(): void
    {
        Sanctum::actingAs($this->user, [], 'web');

        $response = $this->getJson("/api/products/{$this->tyreProduct->id}");

        $response->assertOk()
            ->assertJsonPath('id', $this->tyreProduct->id)
            ->assertJsonPath('type', 'tyre')
            ->assertJsonStructure(['id', 'type', 'brand', 'tyre']);
    }

    public function test_show_returns_404_for_unknown_product(): void
    {
        Sanctum::actingAs($this->user, [], 'web');

        $this->getJson('/api/products/99999')->assertNotFound();
    }

    // -------------------------------------------------------------------------
    // PUT /api/products/{product}
    // -------------------------------------------------------------------------

    public function test_update_requires_permission(): void
    {
        $noPerms = User::query()->create([
            'name' => 'No Perms',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'phone' => '0600000003',
            'commission_rate' => 0,
            'must_change_password' => false,
        ]);

        Sanctum::actingAs($noPerms, [], 'web');

        $this->putJson("/api/products/{$this->tyreProduct->id}", [
            'type' => 'tyre',
        ])->assertForbidden();
    }

    public function test_update_modifies_product_and_tyre_details(): void
    {
        Sanctum::actingAs($this->user, [], 'web');

        $response = $this->putJson("/api/products/{$this->tyreProduct->id}", [
            'type' => 'tyre',
            'profile' => 'Primacy 4',
            'tire_width' => 215,
            'tire_height' => 60,
            'tire_diameter' => 17,
        ]);

        $response->assertOk()
            ->assertJsonPath('profile', 'Primacy 4');

        $this->assertDatabaseHas('product_tyres', [
            'product_id' => $this->tyreProduct->id,
            'tire_width' => 215,
            'tire_height' => 60,
        ]);
    }

    // -------------------------------------------------------------------------
    // PATCH /api/products/{product}/toggle-active
    // -------------------------------------------------------------------------

    public function test_toggle_active_switches_status(): void
    {
        Sanctum::actingAs($this->user, [], 'web');

        $this->assertTrue($this->tyreProduct->is_active);

        $response = $this->patchJson("/api/products/{$this->tyreProduct->id}/toggle-active");

        $response->assertOk()
            ->assertJsonPath('is_active', false);

        $this->assertDatabaseHas('products', [
            'id' => $this->tyreProduct->id,
            'is_active' => false,
        ]);
    }

    // -------------------------------------------------------------------------
    // DELETE /api/products/{product}
    // -------------------------------------------------------------------------

    public function test_destroy_requires_permission(): void
    {
        $noPerms = User::query()->create([
            'name' => 'No Perms',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'phone' => '0600000004',
            'commission_rate' => 0,
            'must_change_password' => false,
        ]);

        Sanctum::actingAs($noPerms, [], 'web');

        $this->deleteJson("/api/products/{$this->tyreProduct->id}")->assertForbidden();
    }

    public function test_destroy_deletes_product(): void
    {
        Sanctum::actingAs($this->user, [], 'web');

        $productId = $this->tyreProduct->id;

        $this->deleteJson("/api/products/{$productId}")->assertNoContent();

        $this->assertDatabaseMissing('products', ['id' => $productId]);
    }

    public function test_destroy_returns_404_for_unknown_product(): void
    {
        Sanctum::actingAs($this->user, [], 'web');

        $this->deleteJson('/api/products/99999')->assertNotFound();
    }

    // -------------------------------------------------------------------------
    // Profile filter & is_active filter
    // -------------------------------------------------------------------------

    public function test_index_filters_by_profile(): void
    {
        Sanctum::actingAs($this->user, [], 'web');

        $suffix = fake()->unique()->numerify('###');

        $withProfile = Product::query()->create([
            'reference' => 'REF-PROF-'.$suffix,
            'type' => 'tyre',
            'profile' => 'Sport',
            'is_active' => true,
        ]);

        Product::query()->create([
            'reference' => 'REF-NO-PROF-'.$suffix,
            'type' => 'tyre',
            'profile' => null,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/products?profile=Sport&per_page=100');
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertContains($withProfile->id, $ids->all());
        foreach ($response->json('data') as $item) {
            $this->assertEquals('Sport', $item['profile']);
        }
    }

    public function test_index_filters_by_is_active(): void
    {
        Sanctum::actingAs($this->user, [], 'web');

        $suffix = fake()->unique()->numerify('###');

        $inactive = Product::query()->create([
            'reference' => 'REF-INACTIVE-'.$suffix,
            'type' => 'tyre',
            'is_active' => false,
        ]);

        $responseActive = $this->getJson('/api/products?is_active=1&per_page=100');
        $responseActive->assertOk();
        $activeIds = collect($responseActive->json('data'))->pluck('id');
        $this->assertNotContains($inactive->id, $activeIds->all());

        $responseInactive = $this->getJson('/api/products?is_active=0&per_page=100');
        $responseInactive->assertOk();
        $inactiveIds = collect($responseInactive->json('data'))->pluck('id');
        $this->assertContains($inactive->id, $inactiveIds->all());
    }

    public function test_filters_returns_profiles_list(): void
    {
        Sanctum::actingAs($this->user, [], 'web');

        $suffix = fake()->unique()->numerify('###');

        Product::query()->create([
            'reference' => 'REF-SPORT-'.$suffix,
            'type' => 'tyre',
            'profile' => 'Profil-'.$suffix,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/products-filters');
        $response->assertOk()->assertJsonStructure(['brands', 'types', 'seasons', 'profiles']);

        $this->assertContains('Profil-'.$suffix, $response->json('profiles'));
    }

    public function test_a_part_reference_is_built_from_its_name_and_brand(): void
    {
        Sanctum::actingAs($this->user, [], 'web');
        $n = (string) random_int(10000, 99999);
        $wix = Brand::query()->create(['name' => 'WIX'.strtoupper(substr(uniqid(), -4)), 'is_active' => true]);
        $generic = Brand::query()->create(['name' => 'FILTRE', 'is_active' => true]);
        $part = fn (array $data) => $this->postJson('/api/products', $data + ['type' => 'part', 'part_category' => 'filters'])->assertCreated()->json('reference');

        // The name copied from the description: initials + the code written in it.
        $this->assertSame("FG-W{$n}", $part(['profile' => "FILTRE GAZOIL W{$n}"]));
        // Same again: made unique.
        $this->assertSame("FG-W{$n}-2", $part(['profile' => "filtre gazoil W{$n}"]));
        // Brand code (first letter when the brand has none) before the code.
        $this->assertSame("FG-W{$n}1", $part(['profile' => "FILTRE GAZOIL {$n}1", 'brand_id' => $wix->id]));
        // Small words and sizes out of the initials; a viscosity / volume is not a code.
        $this->assertSame("FH-HC{$n}", $part(['profile' => "FILTRE A HUILE 5W30 HC{$n}"]));
        // A catalogue "brand" that is a word of the name adds nothing.
        $this->assertSame("FA-{$n}2", $part(['profile' => "FILTRE AIR {$n}2", 'brand_id' => $generic->id]));
        // An OEM reference sent with the product wins over the name.
        $this->assertSame("FA-03N{$n}", $part(['profile' => 'FILTRE AIR', 'oem_reference' => "03N {$n}"]));
        // No code in the name: numbered reference.
        $this->assertMatchesRegularExpression('/^P\d{7}$/', $part(['profile' => 'HUILE 5W30 5L']));

        // The form's preview follows the same rule.
        $this->getJson('/api/products-reference-preview?type=part&profile='.rawurlencode("FILTRE GAZOIL X{$n}"))
            ->assertOk()
            ->assertJsonPath('reference', "FG-X{$n}");
    }
}
