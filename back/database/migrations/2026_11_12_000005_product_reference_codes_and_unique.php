<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Product references become generated and unique:
 *  - brands.code / brand_profiles.code — the short codes the reference is
 *    built from (GITI → GT, SYNERGY → SYN), pre-filled from the references
 *    already in use (most frequent letter prefix / "-suffix" per brand / profile);
 *  - existing duplicate references (compared trimmed, case-insensitive) get a
 *    "-2", "-3"… suffix on the later rows, then products.reference becomes unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->string('code', 10)->nullable()->after('name');
        });
        Schema::table('brand_profiles', function (Blueprint $table) {
            $table->string('code', 15)->nullable()->after('name');
        });

        $this->inferBrandCodes();
        $this->inferProfileCodes();
        $this->deduplicateReferences();

        Schema::table('products', function (Blueprint $table) {
            $table->unique('reference');
        });
    }

    private function inferBrandCodes(): void
    {
        $refs = DB::table('products')
            ->where('type', 'tyre')
            ->whereNotNull('brand_id')
            ->whereNotNull('reference')
            ->get(['brand_id', 'reference'])
            ->groupBy('brand_id');

        foreach ($refs as $brandId => $rows) {
            $prefixes = $rows
                ->map(fn ($r) => preg_match('/^([A-Za-z]{1,6})\d/', trim($r->reference), $m) ? strtoupper($m[1]) : null)
                ->filter()
                ->countBy()
                ->sortDesc();

            if ($prefixes->isNotEmpty()) {
                DB::table('brands')->where('id', $brandId)->whereNull('code')->update(['code' => $prefixes->keys()->first()]);
            }
        }
    }

    private function inferProfileCodes(): void
    {
        $refs = DB::table('products')
            ->where('type', 'tyre')
            ->whereNotNull('brand_id')
            ->whereNotNull('profile')
            ->whereNotNull('reference')
            ->get(['brand_id', 'profile', 'reference']);

        $byProfile = $refs->groupBy(fn ($r) => $r->brand_id.'|'.mb_strtolower(trim($r->profile)));

        foreach (DB::table('brand_profiles')->whereNull('code')->get(['id', 'brand_id', 'name']) as $profile) {
            $rows = $byProfile->get($profile->brand_id.'|'.mb_strtolower(trim($profile->name)));
            if (! $rows) {
                continue;
            }

            $suffixes = $rows
                ->map(fn ($r) => preg_match('/-([A-Za-z0-9.+]{1,15})$/', trim($r->reference), $m) ? strtoupper($m[1]) : null)
                ->filter()
                ->countBy()
                ->sortDesc();

            if ($suffixes->isNotEmpty()) {
                DB::table('brand_profiles')->where('id', $profile->id)->update(['code' => $suffixes->keys()->first()]);
            }
        }
    }

    private function deduplicateReferences(): void
    {
        $duplicates = DB::table('products')
            ->whereNotNull('reference')
            ->selectRaw('LOWER(TRIM(reference)) as ref')
            ->groupByRaw('LOWER(TRIM(reference))')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('ref');

        foreach ($duplicates as $ref) {
            $rows = DB::table('products')->whereRaw('LOWER(TRIM(reference)) = ?', [$ref])->orderBy('id')->get(['id', 'reference']);

            foreach ($rows->slice(1)->values() as $i => $row) {
                $base = trim($row->reference);
                $n = $i + 2;
                while (DB::table('products')->whereRaw('LOWER(TRIM(reference)) = ?', [mb_strtolower("{$base}-{$n}")])->exists()) {
                    $n++;
                }
                DB::table('products')->where('id', $row->id)->update(['reference' => "{$base}-{$n}"]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['reference']);
        });
        Schema::table('brand_profiles', function (Blueprint $table) {
            $table->dropColumn('code');
        });
        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn('code');
        });
    }
};
