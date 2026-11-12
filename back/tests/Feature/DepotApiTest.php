<?php

namespace Tests\Feature;

use App\Models\Depot;
use App\Models\Product;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DepotApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['view stock', 'create stock', 'edit stock'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    private function actingWith(array $permissions): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        Sanctum::actingAs($user, [], 'web');
    }

    private function lotIn(string $depot): Stock
    {
        $product = Product::query()->create(['type' => 'part', 'reference' => 'DEP-'.uniqid(), 'is_active' => true]);

        return Stock::query()->create(['product_id' => $product->id, 'quantity' => 0, 'depot' => $depot]);
    }

    public function test_viewers_list_with_lot_counts_but_cannot_create(): void
    {
        $this->actingWith(['view stock']);
        $depot = Depot::query()->create(['name' => 'DEPOT TEST '.uniqid()]);
        $this->lotIn($depot->name);

        $row = collect($this->getJson('/api/depots')->assertOk()->json())->firstWhere('id', $depot->id);
        $this->assertSame(1, $row['stocks_count']);

        $this->postJson('/api/depots', ['name' => 'NOUVEAU'])->assertForbidden();
    }

    public function test_create_trims_and_refuses_duplicates(): void
    {
        $this->actingWith(['view stock', 'create stock']);
        $name = 'ATELIER '.uniqid();

        $this->postJson('/api/depots', ['name' => "  {$name}  "])->assertCreated()->assertJsonPath('name', $name);
        $this->postJson('/api/depots', ['name' => $name])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson('/api/depots', ['name' => ''])->assertStatus(422);
    }

    public function test_rename_moves_the_lots_and_delete_is_refused_while_used(): void
    {
        $this->actingWith(['view stock', 'edit stock']);
        $depot = Depot::query()->create(['name' => 'OLD '.uniqid()]);
        $lot = $this->lotIn($depot->name);
        $newName = 'NEW '.uniqid();

        $this->putJson("/api/depots/{$depot->id}", ['name' => $newName])
            ->assertOk()
            ->assertJsonPath('stocks_count', 1);
        $this->assertSame($newName, $lot->fresh()->depot);

        $this->deleteJson("/api/depots/{$depot->id}")->assertStatus(422);

        $lot->update(['depot' => null]);
        $this->deleteJson("/api/depots/{$depot->id}")->assertNoContent();
        $this->assertDatabaseMissing('depots', ['id' => $depot->id]);
    }
}
