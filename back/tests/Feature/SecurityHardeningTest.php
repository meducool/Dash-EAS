<?php

namespace Tests\Feature;

use App\Http\Middleware\LimitPerPage;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use DatabaseTransactions;

    private function user(bool $mustChange): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('view brands', 'web');

        $user = User::query()->create([
            'name' => 'Sec Test',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'old-password-123',
            'phone' => '0600000000',
            'commission_rate' => 0,
            'must_change_password' => $mustChange,
        ]);
        $user->givePermissionTo('view brands');

        return $user;
    }

    public function test_user_who_must_change_password_is_blocked_from_the_api(): void
    {
        Sanctum::actingAs($this->user(true));

        $this->getJson('/api/brands')
            ->assertForbidden()
            ->assertJsonPath('must_change_password', true);
    }

    public function test_user_who_must_change_password_can_still_read_session_and_change_it(): void
    {
        Sanctum::actingAs($this->user(true));

        $this->getJson('/api/user')->assertOk();
        $this->postJson('/api/change-password', [
            'current_password' => 'old-password-123',
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertOk();

        $this->getJson('/api/brands')->assertOk();
    }

    public function test_user_with_changed_password_is_not_blocked(): void
    {
        Sanctum::actingAs($this->user(false));

        $this->getJson('/api/brands')->assertOk();
    }

    public function test_per_page_is_capped(): void
    {
        Sanctum::actingAs($this->user(false));

        $this->getJson('/api/brands?per_page=999999')
            ->assertOk()
            ->assertJsonPath('per_page', LimitPerPage::MAX);
    }

    public function test_excel_exports_keep_text_starting_with_equals_as_text(): void
    {
        $sheet = (new Spreadsheet)->getActiveSheet();
        $sheet->fromArray([['=HYPERLINK("http://evil.test","x")', '=1+1', 'ok', 12.5]], null, 'A1', true);

        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('A1')->getDataType());
        $this->assertSame('=HYPERLINK("http://evil.test","x")', $sheet->getCell('A1')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('B1')->getDataType());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('C1')->getDataType());
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('D1')->getDataType());
    }
}
