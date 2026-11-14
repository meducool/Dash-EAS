<?php

namespace App\Console\Commands;

use App\Domain\Webstore\WebstoreAutomation;
use Illuminate\Console\Command;

/**
 * Morning preparation of the online shop batch (catalogue read + proposals).
 * Publishes nothing: the batch waits for the approver.
 */
class PrepareWebstoreCommand extends Command
{
    protected $signature = 'webstore:prepare {--force : même si la préparation automatique est désactivée}';

    protected $description = 'Boutique en ligne : relit le catalogue du site et prépare un lot de propositions (sans rien publier)';

    public function handle(WebstoreAutomation $automation): int
    {
        $result = $automation->run((bool) $this->option('force'));
        $this->line("[{$result['status']}] {$result['message']}");

        return $result['status'] === 'error' ? self::FAILURE : self::SUCCESS;
    }
}
