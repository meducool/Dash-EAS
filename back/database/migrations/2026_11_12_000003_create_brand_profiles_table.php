<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tyre profiles (e.g. "PRIMACY 4") managed under their brand, offered as
 * suggestions in the product form. Products keep their own `profile` text:
 * this is a reference list, not a foreign key.
 *
 * Seeded with the profiles already used by tyre products, per brand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->string('name', 150);
            $table->timestamps();

            $table->unique(['brand_id', 'name']);
        });

        $now = now();
        $existing = DB::table('products')
            ->where('type', 'tyre')
            ->whereNotNull('brand_id')
            ->whereNotNull('profile')
            ->whereRaw("TRIM(profile) <> ''")
            ->selectRaw('brand_id, TRIM(profile) as name')
            ->distinct()
            ->get()
            ->map(fn ($row) => [
                'brand_id' => $row->brand_id,
                'name' => mb_substr($row->name, 0, 150),
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        foreach (array_chunk($existing, 500) as $chunk) {
            DB::table('brand_profiles')->insertOrIgnore($chunk);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_profiles');
    }
};
