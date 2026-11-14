<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Stock;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** GET /api/products/{product}/purchase-prices — "PU Achat" of the sale form. */
class ProductPurchasePriceTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    private Product $product;

    private Stock $lotA;

    private Stock $lotB;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['create sales', 'edit sales', 'view purchases'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $this->user = User::factory()->create();
        $this->product = Product::query()->create(['type' => 'tyre', 'reference' => 'PPA-'.uniqid(), 'is_active' => true]);
        $this->lotA = Stock::query()->create(['product_id' => $this->product->id, 'quantity' => 0]);
        $this->lotB = Stock::query()->create(['product_id' => $this->product->id, 'quantity' => 0]);
    }

    private function buy(Stock $lot, float $price, string $date, string $status = 'RECU', string $supplier = 'FOURN'): Purchase
    {
        $purchase = Purchase::query()->create([
            'date' => $date,
            'supplier_id' => Supplier::query()->create(['name' => $supplier.' '.uniqid(), 'user_id' => $this->user->id])->id,
            'total_quantity' => 4,
            'total_price' => $price * 4,
            'discount' => 0,
            'net_amount' => $price * 4,
            'status' => $status,
            'payment_status' => 'NON PAYE',
            'created_by' => $this->user->id,
        ]);
        PurchaseItem::query()->create(['purchase_id' => $purchase->id, 'product_id' => $this->product->id, 'stock_id' => $lot->id, 'quantity' => 4, 'unit_price' => $price]);

        return $purchase;
    }

    private function prices(?int $stockId = null): array
    {
        $this->user->givePermissionTo('create sales');
        Sanctum::actingAs($this->user, [], 'web');

        return $this->getJson("/api/products/{$this->product->id}/purchase-prices".($stockId ? "?stock_id={$stockId}" : ''))->assertOk()->json();
    }

    public function test_needs_a_sales_permission(): void
    {
        Sanctum::actingAs(User::factory()->create(), [], 'web');
        $this->getJson("/api/products/{$this->product->id}/purchase-prices")->assertForbidden();
    }

    public function test_no_purchase_gives_no_price(): void
    {
        $this->assertSame(['price' => null, 'multiple' => false, 'scope' => null, 'prices' => []], $this->prices());
    }

    public function test_one_price_is_proposed_without_warning(): void
    {
        $this->buy($this->lotA, 800, '2026-10-01');
        $this->buy($this->lotA, 800, '2026-11-01');

        $result = $this->prices($this->lotA->id);
        $this->assertSame(800.0, (float) $result['price']);
        $this->assertFalse($result['multiple']);
        $this->assertSame('stock', $result['scope']);
        $this->assertSame(2, $result['prices'][0]['purchases_count']);
        $this->assertSame(8, $result['prices'][0]['quantity']);
    }

    public function test_several_prices_propose_the_highest_and_list_them_all(): void
    {
        $this->buy($this->lotA, 800, '2026-10-01', supplier: 'ALPHA');
        $this->buy($this->lotA, 950, '2026-09-01', supplier: 'BETA');
        $this->buy($this->lotA, 2000, '2026-11-01', 'ANNULE'); // cancelled: ignored

        $result = $this->prices($this->lotA->id);
        $this->assertSame(950.0, (float) $result['price']);
        $this->assertTrue($result['multiple']);
        $this->assertSame([950.0, 800.0], array_map('floatval', array_column($result['prices'], 'unit_price')));
        $this->assertStringStartsWith('BETA', $result['prices'][0]['suppliers'][0]);
    }

    public function test_a_lot_never_bought_falls_back_to_the_products_purchases(): void
    {
        $this->buy($this->lotA, 700, '2026-10-01');

        $result = $this->prices($this->lotB->id);
        $this->assertSame(700.0, (float) $result['price']);
        $this->assertSame('product', $result['scope']);
    }

    public function test_history_lists_every_purchase_with_supplier_and_price(): void
    {
        $this->lotA->update(['depot' => 'CENTRAL']);
        $this->buy($this->lotA, 800, '2026-09-01', supplier: 'ALPHA');
        $last = $this->buy($this->lotB, 950, '2026-11-01', supplier: 'BETA');
        $this->buy($this->lotA, 2000, '2026-10-01', 'ANNULE', 'GAMMA');

        $this->user->givePermissionTo('view purchases');
        Sanctum::actingAs($this->user, [], 'web');
        $result = $this->getJson("/api/products/{$this->product->id}/purchase-history")->assertOk()->json();

        // Most recent first; the cancelled purchase is listed, flagged.
        $this->assertSame([950.0, 2000.0, 800.0], array_map('floatval', array_column($result['data'], 'unit_price')));
        $this->assertSame($last->id, $result['data'][0]['purchase_id']);
        $this->assertStringStartsWith('BETA', $result['data'][0]['supplier']['name']);
        $this->assertTrue($result['data'][1]['cancelled']);
        $this->assertSame('CENTRAL', $result['data'][2]['depot']);

        // Summary without the cancelled one: 4 × 800 + 4 × 950.
        $this->assertSame(2, $result['summary']['purchases_count']);
        $this->assertSame(950.0, (float) $result['summary']['last_price']);
        $this->assertSame(800.0, (float) $result['summary']['min_price']);
        $this->assertSame(875.0, (float) $result['summary']['average_price']);
        $this->assertSame(2, $result['summary']['suppliers_count']);
    }

    public function test_history_needs_view_purchases(): void
    {
        $this->user->givePermissionTo('create sales');
        Sanctum::actingAs($this->user, [], 'web');
        $this->getJson("/api/products/{$this->product->id}/purchase-history")->assertForbidden();
    }
}
