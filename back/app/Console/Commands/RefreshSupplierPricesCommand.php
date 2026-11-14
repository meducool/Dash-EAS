<?php

namespace App\Console\Commands;

use App\Domain\SupplierPrices\SupplierPriceFileService;
use App\Models\SupplierPriceFile;
use Illuminate\Console\Command;
use Throwable;

/** Re-reads every active price list published online with "relire chaque nuit" on. */
class RefreshSupplierPricesCommand extends Command
{
    protected $signature = 'supplier-prices:refresh';

    protected $description = 'Relit les tarifs fournisseurs publiés en ligne (rafraîchissement automatique)';

    public function handle(SupplierPriceFileService $files): int
    {
        $list = SupplierPriceFile::query()
            ->where('source_type', 'url')
            ->where('auto_refresh', true)
            ->where('is_active', true)
            ->get();

        foreach ($list as $file) {
            try {
                $file = $files->refresh($file);
                $this->info("{$file->name} : {$file->rows_count} lignes.");
            } catch (Throwable $e) {
                // The error is kept on the file (last_error) and shown on the page.
                $this->warn("{$file->name} : échec — ".($file->fresh()->last_error ?? $e->getMessage()));
            }
        }

        return self::SUCCESS;
    }
}
