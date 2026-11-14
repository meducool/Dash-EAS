<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tarifs fournisseurs: the selling price is no longer read from the file, it is
 * computed — Prix TTC × (1 + margin %) — with a default margin set by the
 * administrator (company_settings) that a price list may override.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->decimal('supplier_price_margin', 6, 2)->default(0)->after('prime_threshold');
        });

        Schema::table('supplier_price_files', function (Blueprint $table) {
            $table->decimal('margin_percent', 6, 2)->nullable()->after('column_mapping');
        });

        Schema::table('supplier_price_items', function (Blueprint $table) {
            $table->dropColumn('sale_price');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_price_items', function (Blueprint $table) {
            $table->decimal('sale_price', 12, 2)->nullable()->after('price');
        });

        Schema::table('supplier_price_files', function (Blueprint $table) {
            $table->dropColumn('margin_percent');
        });

        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('supplier_price_margin');
        });
    }
};
