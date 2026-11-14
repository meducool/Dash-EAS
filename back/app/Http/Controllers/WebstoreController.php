<?php

namespace App\Http\Controllers;

use App\Domain\Webstore\WebstoreAutomation;
use App\Domain\Webstore\WebstoreCatalogService;
use App\Domain\Webstore\WebstoreLock;
use App\Domain\Webstore\WebstoreMatcher;
use App\Domain\Webstore\WebstoreProposalService;
use App\Domain\Webstore\WebstorePublisher;
use App\Models\WebstoreChange;
use App\Models\WebstoreSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Boutique en ligne (WooCommerce) — connection and SKU matching report (read only). */
class WebstoreController extends Controller
{
    public function __construct(
        private WebstoreCatalogService $catalog,
        private WebstoreProposalService $proposals,
        private WebstorePublisher $publisher,
        private WebstoreLock $lock,
        private WebstoreAutomation $automation,
        private WebstoreMatcher $matcher,
    ) {}

    public function settings(): JsonResponse
    {
        return response()->json($this->catalog->settings());
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'store_url' => ['sometimes', 'nullable', 'string', 'max:255'],
            'consumer_key' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^ck_[A-Za-z0-9]+$/'],
            'consumer_secret' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^cs_[A-Za-z0-9]+$/'],
            'clear_credentials' => ['sometimes', 'boolean'],
            'margin_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:500'],
            'create_status' => ['sometimes', Rule::in(['draft', 'publish'])],
            'auto_prepare' => ['sometimes', 'boolean'],
        ], [
            'consumer_key.regex' => 'La clé client WooCommerce commence par « ck_ ».',
            'consumer_secret.regex' => 'Le secret client WooCommerce commence par « cs_ ».',
        ]);

        return response()->json($this->catalog->updateSettings($validated));
    }

    public function test(): JsonResponse
    {
        return response()->json($this->catalog->testConnection());
    }

    public function pull(): JsonResponse
    {
        return response()->json($this->lock->run(fn () => $this->catalog->pull()));
    }

    public function report(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(WebstoreCatalogService::STATUSES)],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        return response()->json($this->catalog->report($validated));
    }

    // ── Step 2: proposals + approval ─────────────────────────────────────────

    /** Lines of the open batch (+ batch info). */
    public function changes(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in([WebstoreChange::PROPOSED, WebstoreChange::APPROVED, WebstoreChange::REJECTED, WebstoreChange::PUBLISHED, WebstoreChange::FAILED])],
            'action' => ['nullable', Rule::in([WebstoreChange::CREATE, WebstoreChange::UPDATE])],
            'alert' => ['nullable', Rule::in(['none', 'any', ...WebstoreProposalService::ALERTS])],
            'batch_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        return response()->json($this->proposals->changes($validated));
    }

    /** Computes a new batch of proposals (the previous open one is superseded). */
    public function generate(Request $request): JsonResponse
    {
        return response()->json($this->lock->run(fn () => $this->proposals->generate($request->user())), 201);
    }

    public function decide(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject', 'reset'])],
            'scope' => ['nullable', Rule::in(['ids', 'no_alerts'])],
            'ids' => ['required_unless:scope,no_alerts', 'array', 'max:1000'],
            'ids.*' => ['integer'],
        ]);
        $scope = $validated['scope'] ?? 'ids';
        if ($scope === 'no_alerts' && $validated['decision'] !== 'approve') {
            return response()->json(['message' => 'Seule l\'approbation peut viser toutes les lignes sans alerte.', 'errors' => ['scope' => ['Portée invalide.']]], 422);
        }

        return response()->json($this->proposals->decide($validated['decision'], $validated['ids'] ?? [], $scope, $request->user()));
    }

    public function price(Request $request, WebstoreChange $change): JsonResponse
    {
        $validated = $request->validate([
            'price' => ['present', 'nullable', 'numeric', 'min:0.01', 'max:1000000'],
        ]);

        return response()->json($this->proposals->overridePrice($change, $validated['price'] === null ? null : (float) $validated['price'], $request->user()));
    }

    // ── Step 3: publishing ───────────────────────────────────────────────────

    /** Sends the approved lines of the open batch to the shop. */
    public function publish(Request $request): JsonResponse
    {
        return response()->json($this->lock->run(fn () => $this->publisher->publish($request->user())));
    }

    // ── Step 4: morning preparation, status, history ─────────────────────────

    /** Open batch counts + last morning run — feeds the banner and the badge of the tab. */
    public function status(): JsonResponse
    {
        $settings = WebstoreSetting::current();
        $batch = $this->proposals->batchInfo($this->proposals->openBatch())['batch'];

        return response()->json([
            'batch' => $batch,
            'auto' => [
                'enabled' => (bool) $settings->auto_prepare,
                'last_run_at' => $settings->last_auto_run_at?->toIso8601String(),
                'last_result' => $settings->last_auto_result,
            ],
            'last_publish_at' => $settings->last_publish_at?->toIso8601String(),
        ]);
    }

    /** Runs the morning preparation now (even when switched off). Publishes nothing. */
    public function prepareNow(): JsonResponse
    {
        return response()->json($this->automation->run(true));
    }

    public function batches(Request $request): JsonResponse
    {
        $validated = $request->validate(['page' => ['nullable', 'integer', 'min:1']]);

        return response()->json($this->proposals->history((int) ($validated['page'] ?? 1)));
    }

    // ── Anti-duplication: association by hand ───────────────────────────────

    /** Links a site product (unknown / empty SKU) to a POS article; its SKU correction is then proposed. */
    public function link(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'woo_id' => ['required', 'integer', 'min:1'],
            'product_id' => ['required', 'integer', 'min:1'],
        ]);
        $this->lock->run(fn () => $this->matcher->link((int) $validated['woo_id'], (int) $validated['product_id'], $request->user()));

        return response()->json(['ok' => true, 'summary' => $this->catalog->summary()]);
    }

    public function unlink(Request $request, int $wooId): JsonResponse
    {
        $this->lock->run(fn () => $this->matcher->unlink($wooId, $request->user()));

        return response()->json(['ok' => true, 'summary' => $this->catalog->summary()]);
    }
}
