<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Boutique en ligne — step 2: batches of proposed changes for the WooCommerce
     * shop, each line approved or rejected by hand before anything is published.
     */
    public function up(): void
    {
        Schema::create('webstore_batches', function (Blueprint $table) {
            $table->id();
            $table->string('status', 20)->default('open'); // open | superseded
            $table->decimal('margin_percent', 6, 2);      // margin used for the prices of this batch
            $table->timestamp('catalogue_read_at')->nullable(); // site catalogue the batch was computed from
            $table->json('counts')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->timestamps();
        });

        Schema::create('webstore_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('webstore_batches')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('woo_id')->nullable(); // null = creation
            $table->string('action', 20);                      // create | update
            $table->json('fields');                            // changed fields: price, stock, images
            $table->json('current')->nullable();               // values on the site when computed
            $table->json('proposed');                          // values to publish
            $table->json('alerts');                            // price_jump, below_cost, no_photo, site_draft…
            $table->decimal('price_override', 12, 2)->nullable(); // price typed by the approver
            // proposed | approved | rejected | published | failed | superseded
            $table->string('status', 20)->default('proposed')->index();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decided_by_name')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webstore_changes');
        Schema::dropIfExists('webstore_batches');
    }
};
