<?php

namespace App\Domain\Webstore;

use App\Models\WebstoreChange;
use App\Models\WebstoreSetting;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Boutique en ligne — step 4: every morning (after the 06:00 supplier price
 * refresh) the site catalogue is read again and a batch of proposals is computed.
 * Nothing is ever published here: the batch waits for the approver.
 *
 * A batch holding approved lines not published yet is never replaced
 * automatically — that would drop decisions taken by hand.
 */
class WebstoreAutomation
{
    public const BY = 'Automatique';

    public function __construct(
        private WebstoreCatalogService $catalog,
        private WebstoreProposalService $proposals,
        private WebstoreLock $lock,
    ) {}

    /**
     * @param  bool  $force  run even when the morning preparation is switched off ("Préparer maintenant")
     * @return array<string, mixed> status ok|skipped|error|off, message, counts
     */
    public function run(bool $force = false): array
    {
        $settings = WebstoreSetting::current();
        if (! $force && ! $settings->auto_prepare) {
            return ['status' => 'off', 'message' => 'Préparation automatique désactivée.'];
        }

        $result = $this->attempt($settings);
        $settings->forceFill(['last_auto_run_at' => now(), 'last_auto_result' => $result])->save();

        return $result;
    }

    /** @return array<string, mixed> */
    private function attempt(WebstoreSetting $settings): array
    {
        if (! $settings->hasCredentials() || $settings->margin_percent === null) {
            return ['status' => 'skipped', 'message' => 'Connexion au site ou marge boutique non renseignée.'];
        }

        $pending = $this->proposals->openBatch()?->changes()->where('status', WebstoreChange::APPROVED)->count() ?? 0;
        if ($pending > 0) {
            return ['status' => 'skipped', 'message' => "Lot actuel conservé : {$pending} ligne(s) approuvée(s) attendent la publication."];
        }

        try {
            $batch = $this->lock->run(function () {
                $this->catalog->pull();

                return $this->proposals->generate(null, self::BY)['batch'];
            });
        } catch (ValidationException $e) {
            return ['status' => 'error', 'message' => (string) collect($e->errors())->flatten()->first()];
        } catch (Throwable $e) {
            report($e);

            return ['status' => 'error', 'message' => 'Erreur inattendue ('.class_basename($e).').'];
        }

        $c = $batch['counts'];

        return [
            'status' => 'ok',
            'message' => "Lot #{$batch['id']} préparé : {$c['update']} mise(s) à jour, {$c['create']} création(s), dont {$c['with_alerts']} avec alerte.",
            'counts' => ['batch_id' => $batch['id'], 'update' => $c['update'], 'create' => $c['create'], 'with_alerts' => $c['with_alerts']],
        ];
    }
}
