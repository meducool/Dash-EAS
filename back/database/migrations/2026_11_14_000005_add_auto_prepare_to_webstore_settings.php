<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Boutique en ligne — step 4: a batch of proposals prepared automatically
     * every morning (catalogue read + computation). Never published without approval.
     */
    public function up(): void
    {
        Schema::table('webstore_settings', function (Blueprint $table) {
            $table->boolean('auto_prepare')->default(false)->after('create_status');
            $table->timestamp('last_auto_run_at')->nullable()->after('last_publish_at');
            $table->json('last_auto_result')->nullable()->after('last_auto_run_at');
        });
    }

    public function down(): void
    {
        Schema::table('webstore_settings', function (Blueprint $table) {
            $table->dropColumn(['auto_prepare', 'last_auto_run_at', 'last_auto_result']);
        });
    }
};
