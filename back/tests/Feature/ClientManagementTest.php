<?php

namespace Tests\Feature;

use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\StoreSaleRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Models\Client;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalePaymentAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ClientManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_profile_endpoint_returns_summary_metrics_and_sales_history(): void
    {
        $user = $this->authenticateWithPermissions(['view clients']);

        $client = Client::create([
            'name' => 'Acme Stores',
            'category' => 'Entreprise',
            'phone' => '0600000000',
            'city' => 'Casablanca',
            'opening_balance' => 50,
            'credit_limit' => 500,
            'is_active' => true,
        ]);

        $firstSale = Sale::create([
            'client_id' => $client->id,
            'city' => 'Casablanca',
            'date' => now()->subDays(2)->toDateString(),
            'total_sale' => 100,
            'status' => 'partial',
        ]);

        $secondSale = Sale::create([
            'client_id' => $client->id,
            'city' => 'Casablanca',
            'date' => now()->subDay()->toDateString(),
            'total_sale' => 80,
            'status' => 'paid',
        ]);

        $firstPayment = Payment::create([
            'sale_id' => $firstSale->id,
            'amount' => 40,
            'date' => now()->subDays(2)->toDateString(),
            'method' => 'cash',
            'notes' => 'Partial payment',
            'user_id' => $user->id,
        ]);
        SalePaymentAllocation::create(['payment_id' => $firstPayment->id, 'sale_id' => $firstSale->id, 'amount' => 40]);

        $secondPayment = Payment::create([
            'sale_id' => $secondSale->id,
            'amount' => 80,
            'date' => now()->subDay()->toDateString(),
            'method' => 'cash',
            'notes' => 'Full payment',
            'user_id' => $user->id,
        ]);
        SalePaymentAllocation::create(['payment_id' => $secondPayment->id, 'sale_id' => $secondSale->id, 'amount' => 80]);

        $response = $this->getJson('/api/clients/'.$client->id.'/profile');

        $response
            ->assertOk()
            ->assertJsonPath('data.client.id', $client->id)
            ->assertJsonPath('data.client.category', 'Entreprise')
            ->assertJsonPath('data.sales_count', 2)
            ->assertJsonPath('data.total_purchased', 180)
            ->assertJsonPath('data.outstanding_balance', 110)
            ->assertJsonCount(2, 'data.sales_history');
    }

    public function test_clients_summary_lists_only_clients_with_a_balance(): void
    {
        $user = $this->authenticateWithPermissions(['view clients']);

        $owing = Client::create(['name' => 'Client Doit', 'category' => 'Particulier', 'phone' => '0600000101', 'opening_balance' => 50, 'is_active' => true]);
        $paid = Client::create(['name' => 'Client Solde', 'category' => 'Particulier', 'phone' => '0600000102', 'opening_balance' => 0, 'is_active' => true]);
        $bigger = Client::create(['name' => 'Client Gros', 'category' => 'Particulier', 'phone' => '0600000103', 'opening_balance' => 0, 'is_active' => true]);

        // Owing: 50 opening + 100 sale (40 paid) → 110. A cancelled sale is not owed.
        $sale = Sale::create(['client_id' => $owing->id, 'date' => now()->toDateString(), 'total_sale' => 100, 'status' => 'EN COURS']);
        $payment = Payment::create(['sale_id' => $sale->id, 'amount' => 40, 'date' => now()->toDateString(), 'method' => 'Espèces', 'user_id' => $user->id]);
        SalePaymentAllocation::create(['payment_id' => $payment->id, 'sale_id' => $sale->id, 'amount' => 40]);
        Sale::create(['client_id' => $owing->id, 'date' => now()->toDateString(), 'total_sale' => 999, 'status' => 'ANNULE']);

        // Fully paid: not listed.
        $paidSale = Sale::create(['client_id' => $paid->id, 'date' => now()->toDateString(), 'total_sale' => 80, 'status' => 'LIVRE']);
        $paidPayment = Payment::create(['sale_id' => $paidSale->id, 'amount' => 80, 'date' => now()->toDateString(), 'method' => 'Espèces', 'user_id' => $user->id]);
        SalePaymentAllocation::create(['payment_id' => $paidPayment->id, 'sale_id' => $paidSale->id, 'amount' => 80]);

        Sale::create(['client_id' => $bigger->id, 'date' => now()->toDateString(), 'total_sale' => 300, 'status' => 'LIVRE']);

        $response = $this->getJson('/api/clients-summary')->assertOk();

        $this->assertSame([$bigger->id, $owing->id], array_column($response->json('rows'), 'client_id'));
        $response->assertJsonPath('rows.1.total_unpaid', 110)
            ->assertJsonPath('rows.1.opening_balance', 50)
            ->assertJsonPath('rows.1.sales_unpaid', 60)
            ->assertJsonPath('total', 410);

        // The profile's "solde dû" follows the same rule (cancelled sale excluded).
        $this->getJson("/api/clients/{$owing->id}/profile")->assertJsonPath('data.outstanding_balance', 110);
    }

    public function test_clients_summary_requires_view_clients_permission(): void
    {
        $this->authenticateWithPermissions([]);

        $this->getJson('/api/clients-summary')->assertForbidden();
    }

    public function test_client_statement_endpoint_returns_summary_sales_payments_and_entries(): void
    {
        $user = $this->authenticateWithPermissions(['view clients']);

        $client = Client::create([
            'name' => 'Beta Client',
            'category' => 'Particulier',
            'phone' => '0700000000',
            'city' => 'Rabat',
            'opening_balance' => 25,
            'is_active' => true,
        ]);

        $sale = Sale::create([
            'client_id' => $client->id,
            'city' => 'Rabat',
            'date' => now()->subDay()->toDateString(),
            'total_sale' => 120,
            'status' => 'partial',
        ]);

        $payment = Payment::create([
            'sale_id' => $sale->id,
            'amount' => 20,
            'date' => now()->toDateString(),
            'method' => 'cash',
            'notes' => 'Initial payment',
            'user_id' => $user->id,
        ]);
        SalePaymentAllocation::create(['payment_id' => $payment->id, 'sale_id' => $sale->id, 'amount' => 20]);

        $response = $this->getJson('/api/clients/'.$client->id.'/statement');

        $response
            ->assertOk()
            ->assertJsonPath('data.client.id', $client->id)
            ->assertJsonPath('data.client.category', 'Particulier')
            ->assertJsonPath('data.summary.sales_count', 1)
            ->assertJsonPath('data.summary.opening_balance', 25)
            ->assertJsonPath('data.summary.total_sales', 120)
            ->assertJsonPath('data.summary.total_paid', 20)
            ->assertJsonPath('data.summary.outstanding_balance', 125)
            ->assertJsonCount(1, 'data.sales')
            ->assertJsonCount(1, 'data.payments');

        $this->assertGreaterThanOrEqual(2, count($response->json('data.entries')));
    }

    public function test_client_statement_orders_entries_by_date_descending(): void
    {
        $this->authenticateWithPermissions(['view clients']);

        $client = Client::create([
            'name' => 'Ordering Client',
            'category' => 'Particulier',
            'phone' => '0701111111',
            'is_active' => true,
        ]);

        Sale::create([
            'client_id' => $client->id,
            'date' => '2026-01-10',
            'total_sale' => 100,
            'status' => 'partial',
        ]);

        Sale::create([
            'client_id' => $client->id,
            'date' => '2026-03-15',
            'total_sale' => 50,
            'status' => 'partial',
        ]);

        $response = $this->getJson('/api/clients/'.$client->id.'/statement');

        $response->assertOk();
        $dates = collect($response->json('data.entries'))->pluck('date')->all();

        $this->assertSame($dates, collect($dates)->sortDesc()->values()->all(), 'Entries must be ordered newest first.');
        $this->assertSame('2026-03-15', $dates[0]);
        $this->assertSame('2026-01-10', $dates[count($dates) - 1]);
    }

    public function test_store_client_request_requires_allowed_category_values(): void
    {
        $missingCategoryValidator = Validator::make([
            'name' => 'Validator Client',
        ], (new StoreClientRequest)->rules());

        $this->assertTrue($missingCategoryValidator->passes());

        $invalidCategoryValidator = Validator::make([
            'name' => 'Validator Client',
            'category' => 'Invalid',
        ], (new StoreClientRequest)->rules());

        $this->assertFalse($invalidCategoryValidator->passes());
        $this->assertArrayHasKey('category', $invalidCategoryValidator->errors()->toArray());

        $validCategoryValidator = Validator::make([
            'name' => 'Validator Client',
            'category' => 'Particulier',
        ], (new StoreClientRequest)->rules());

        $this->assertTrue($validCategoryValidator->passes(), json_encode($validCategoryValidator->errors()->toArray()));

        $enterpriseCategoryValidator = Validator::make([
            'name' => 'Validator Client',
            'category' => 'Entreprise',
        ], (new StoreClientRequest)->rules());

        $this->assertTrue($enterpriseCategoryValidator->passes(), json_encode($enterpriseCategoryValidator->errors()->toArray()));
    }

    public function test_update_client_request_rejects_invalid_category_values(): void
    {
        $client = Client::create([
            'name' => 'Update Validator Client',
            'category' => 'Particulier',
            'phone' => '0811111111',
            'is_active' => true,
        ]);

        $request = UpdateClientRequest::create('/api/clients/'.$client->id, 'PATCH', [
            'category' => 'Invalid',
        ]);

        $request->setRouteResolver(function () use ($client) {
            return new class($client)
            {
                public function __construct(private Client $client) {}

                public function parameter(string $key): mixed
                {
                    return $key === 'client' ? $this->client : null;
                }
            };
        });

        $validator = Validator::make($request->all(), $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('category', $validator->errors()->toArray());
    }

    public function test_store_sale_request_accepts_existing_client_id(): void
    {
        $client = Client::create([
            'name' => 'Validator Client',
            'category' => 'Particulier',
            'phone' => '0800000000',
            'is_active' => true,
        ]);

        $product = Product::create([
            'reference' => 'VALIDATOR-PRODUCT',
            'type' => 'tyre',
            'is_active' => true,
        ]);

        $commercial = User::factory()->create();

        $partner = Partner::create([
            'name' => 'Validator Partner',
            'is_active' => true,
            'user_id' => $commercial->id,
        ]);

        $validator = Validator::make([
            'date' => now()->toDateString(),
            'client_id' => $client->id,
            'commercial_id' => $commercial->id,
            'partner_id' => $partner->id,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'selling_price' => 99.99,
            ]],
        ], (new StoreSaleRequest)->rules());

        $this->assertTrue($validator->passes(), json_encode($validator->errors()->toArray()));
    }

    protected function authenticateWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permissionName) {
            Permission::findOrCreate($permissionName);
            $user->givePermissionTo($permissionName);
        }

        Sanctum::actingAs($user);

        return $user;
    }
}
