<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Anti-duplication: a POS article and a WooCommerce product, once linked,
     * stay linked by the site's product id — not only by SKU — so a SKU typed
     * differently on the site, or a reference corrected in the POS, never leads
     * to a second product on the site.
     */
    public function up(): void
    {
        Schema::create('webstore_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('woo_id')->unique();
            $table->string('source', 10); // sku (same SKU found) | manual (associated by hand) | created (created by the POS)
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->timestamps();
        });

        Schema::table('webstore_products', function (Blueprint $table) {
            // Site product with an unknown / empty SKU that looks like this POS article (name read like a price list line).
            $table->foreignId('suggested_product_id')->nullable()->after('product_id')->constrained('products')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('webstore_products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('suggested_product_id');
        });
        Schema::dropIfExists('webstore_links');
    }
};
