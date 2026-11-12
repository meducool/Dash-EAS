<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks the deposit of a received cheque / effet at the bank, on the cheque
 * line itself (no separate transfer): `deposited_at` is set once it is
 * deposited (or marked as already deposited), and `deposited_from_account_id`
 * keeps the account it was moved from (e.g. the Portefeuille) so the deposit
 * can be undone — null when the cheque was only marked as deposited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->date('deposited_at')->nullable()->after('transfer_id');
            $table->foreignId('deposited_from_account_id')->nullable()->after('deposited_at')
                ->constrained('accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deposited_from_account_id');
            $table->dropColumn('deposited_at');
        });
    }
};
