<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Boutique en ligne (WooCommerce): connection settings (singleton, API keys
     * encrypted) and the last read copy of the site catalogue, matched by SKU.
     */
    public function up(): void
    {
        Schema::create('webstore_settings', function (Blueprint $table) {
            $table->id();
            $table->string('store_url')->nullable();
            $table->text('consumer_key')->nullable();    // encrypted cast
            $table->text('consumer_secret')->nullable(); // encrypted cast
            $table->string('consumer_key_hint', 8)->nullable(); // last 4 chars, shown in the settings
            // Selling price on the site = supplier TTC price × (1 + margin %) — used from step 2.
            $table->decimal('margin_percent', 6, 2)->nullable();
            $table->timestamp('last_pull_at')->nullable();
            $table->json('last_pull_summary')->nullable();
            $table->timestamps();
        });

        Schema::create('webstore_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('woo_id')->unique();
            $table->string('sku')->nullable();
            $table->string('normalized_sku')->nullable()->index();
            $table->string('name');
            $table->string('type', 20)->nullable();
            $table->string('status', 20)->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('regular_price', 12, 2)->nullable();
            $table->decimal('sale_price', 12, 2)->nullable();
            $table->string('stock_status', 20)->nullable();
            $table->integer('stock_quantity')->nullable();
            $table->unsignedSmallInteger('images_count')->default(0);
            $table->string('permalink', 500)->nullable();
            $table->timestamp('woo_modified_at')->nullable();
            // matched | site_only (SKU unknown to the POS) | no_sku | duplicate (same SKU twice on the site)
            $table->string('match_status', 20)->index();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webstore_products');
        Schema::dropIfExists('webstore_settings');
    }
};
