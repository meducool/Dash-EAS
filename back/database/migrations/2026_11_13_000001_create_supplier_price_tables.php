<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tarifs fournisseurs: price lists (Excel files uploaded or read from a URL)
 * and the lines read from them, identified as brand / profile / dimensions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_price_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('source_type', 10)->default('upload'); // upload | url
            $table->string('source_url', 2048)->nullable();
            $table->boolean('auto_refresh')->default(false);
            $table->string('stored_path')->nullable();
            $table->string('original_filename')->nullable();
            $table->json('column_mapping')->nullable();
            $table->json('headers')->nullable();
            $table->unsignedInteger('rows_count')->default(0);
            $table->unsignedInteger('identified_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_imported_at')->nullable();
            $table->text('last_error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('supplier_price_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_id')->constrained('supplier_price_files')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('designation', 500);
            $table->string('supplier_reference', 100)->nullable();
            $table->string('brand_name', 100)->nullable();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->string('profile', 150)->nullable();
            $table->decimal('width', 6, 2)->nullable();
            $table->decimal('height', 6, 2)->nullable();
            $table->decimal('diameter', 5, 2)->nullable();
            $table->string('load_index', 10)->nullable();
            $table->string('speed_index', 5)->nullable();
            $table->boolean('runflat')->default(false);
            $table->boolean('reinforced')->default(false);
            $table->string('season', 20)->nullable();
            $table->decimal('price', 12, 2);
            $table->string('availability', 50)->nullable();
            $table->string('match_key', 120)->nullable();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->timestamps();

            $table->index(['width', 'height', 'diameter']);
            $table->index('match_key');
            $table->index('brand_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_price_items');
        Schema::dropIfExists('supplier_price_files');
    }
};
