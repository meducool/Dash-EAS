<?php

namespace App\Console\Commands;

use App\Domain\Products\TyreDescriptionCompleter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Adds the load / speed indexes to the descriptions of existing tyres.
 * Preview by default (nothing written); --apply writes. Descriptions whose
 * indexes contradict the product sheet are never changed (listed to check).
 */
class CompleteTyreDescriptionsCommand extends Command
{
    protected $signature = 'products:complete-descriptions
        {--apply : écrit les changements (sinon simple aperçu)}
        {--with-empty : remplit aussi les descriptions vides}
        {--csv= : chemin d\'un fichier CSV pour l\'aperçu}';

    protected $description = 'Ajoute les indices de charge et de vitesse aux descriptions des pneus (aperçu par défaut)';

    public function handle(TyreDescriptionCompleter $completer): int
    {
        $rows = DB::table('products')
            ->join('product_tyres', 'product_tyres.product_id', '=', 'products.id')
            ->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
            ->where('products.type', 'tyre')
            ->orderBy('products.id')
            ->get(['products.id', 'products.reference', 'products.description', 'products.profile', 'brands.name as brand',
                'product_tyres.tire_width', 'product_tyres.tire_height', 'product_tyres.tire_diameter',
                'product_tyres.tire_load_index', 'product_tyres.tire_speed_index']);

        $changes = [];
        $counts = [TyreDescriptionCompleter::ADDED => 0, TyreDescriptionCompleter::BUILT => 0, TyreDescriptionCompleter::CONFLICT => 0, TyreDescriptionCompleter::INVALID => 0, TyreDescriptionCompleter::NO_DIMENSION => 0];
        foreach ($rows as $r) {
            $result = $completer->complete($r->description, [
                'width' => $r->tire_width, 'height' => $r->tire_height, 'diameter' => $r->tire_diameter,
                'load_index' => $r->tire_load_index, 'speed_index' => $r->tire_speed_index,
                'brand' => $r->brand, 'profile' => $r->profile,
            ]);
            $status = $result['status'];
            if ($status === TyreDescriptionCompleter::UNCHANGED || ($status === TyreDescriptionCompleter::BUILT && ! $this->option('with-empty'))) {
                if ($status === TyreDescriptionCompleter::BUILT) {
                    $counts[$status]++;
                }

                continue;
            }
            $counts[$status]++;
            $changes[] = [
                'id' => $r->id,
                'reference' => $r->reference,
                'status' => $status,
                'before' => (string) $r->description,
                'after' => in_array($status, [TyreDescriptionCompleter::CONFLICT, TyreDescriptionCompleter::INVALID, TyreDescriptionCompleter::NO_DIMENSION], true) ? '' : $result['description'],
                'sheet' => trim(($r->tire_load_index ?? '').($r->tire_speed_index ?? '')),
                'written' => $result['written'] ?? '',
            ];
        }

        if ($path = $this->option('csv')) {
            $out = fopen($path, 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($out, ['ID', 'Référence', 'Action', 'Description actuelle', 'Nouvelle description', 'Indices fiche', 'Indices écrits'], ';');
            $labels = ['added' => 'Indices ajoutés', 'built' => 'Description créée', 'conflict' => 'À vérifier : indices différents (non modifié)', 'invalid' => 'À corriger : indices de la fiche invalides (non modifié)', 'no_dimension' => 'À vérifier : pas de dimension lisible (non modifié)'];
            foreach ($changes as $c) {
                fputcsv($out, [$c['id'], $c['reference'], $labels[$c['status']], $c['before'], $c['after'], $c['sheet'], $c['written']], ';');
            }
            fclose($out);
        }

        $this->info("Indices ajoutés : {$counts['added']} · descriptions vides".($this->option('with-empty') ? ' remplies' : ' (non traitées sans --with-empty)')." : {$counts['built']} · à vérifier, indices différents : {$counts['conflict']} · fiches aux indices invalides : {$counts['invalid']} · sans dimension lisible : {$counts['no_dimension']}");

        if (! $this->option('apply')) {
            $this->line('Aperçu seulement : rien n\'a été écrit. Relancez avec --apply pour enregistrer.');

            return self::SUCCESS;
        }

        $written = 0;
        DB::transaction(function () use ($changes, &$written) {
            foreach ($changes as $c) {
                if (! in_array($c['status'], [TyreDescriptionCompleter::ADDED, TyreDescriptionCompleter::BUILT], true)) {
                    continue;
                }
                $written += DB::table('products')->where('id', $c['id'])->update(['description' => $c['after'], 'updated_at' => now()]);
            }
        });
        $this->info("{$written} description(s) enregistrée(s).");

        return self::SUCCESS;
    }
}
