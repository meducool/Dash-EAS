<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reference list of depots (warehouses), chosen from a list instead of typed
 * on every stock lot. Stock lots keep their `depot` text column: renaming a
 * depot renames the lots that carry it.
 *
 * Seeded with the depots already used by stock lots.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('depots', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->timestamps();
        });

        $now = now();
        $existing = DB::table('stocks')
            ->whereNotNull('depot')
            ->whereRaw("TRIM(depot) <> ''")
            ->selectRaw('TRIM(depot) as name')
            ->distinct()
            ->pluck('name')
            ->map(fn ($name) => ['name' => mb_substr($name, 0, 100), 'created_at' => $now, 'updated_at' => $now])
            ->all();

        foreach (array_chunk($existing, 500) as $chunk) {
            DB::table('depots')->insertOrIgnore($chunk);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('depots');
    }
};
