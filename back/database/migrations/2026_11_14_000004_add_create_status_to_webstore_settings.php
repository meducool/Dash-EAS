<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Status of the products the POS creates on the shop: draft (checked on WordPress first) or publish. */
    public function up(): void
    {
        Schema::table('webstore_settings', function (Blueprint $table) {
            $table->string('create_status', 10)->default('draft')->after('margin_percent');
            $table->timestamp('last_publish_at')->nullable()->after('last_pull_summary');
        });
    }

    public function down(): void
    {
        Schema::table('webstore_settings', function (Blueprint $table) {
            $table->dropColumn(['create_status', 'last_publish_at']);
        });
    }
};
