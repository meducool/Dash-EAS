<?php

namespace App\Domain\Webstore;

use App\Models\User;
use App\Models\WebstoreBatch;
use App\Models\WebstoreChange;
use App\Models\WebstoreProduct;
use App\Models\WebstoreSetting;
use App\Services\ActivityLogService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Boutique en ligne — step 3: sends the APPROVED lines of the open batch to the
 * WooCommerce shop (wc/v3 products/batch). Every other line is left alone.
 *
 * Safety:
 * - an update is refused when the product was modified on the site after the
 *   catalogue was read (someone changed it by hand) or no longer exists;
 * - photos are sent only when the site can download them (public address),
 *   otherwise the rest of the line is published and a warning is kept;
 * - a request refused as a whole (keys, site down) stops the run: the lines not
 *   sent yet stay approved and can be published again.
 */
class WebstorePublisher
{
    public function __construct(
        private WooCommerceClient $client,
        private WebstoreCatalogService $catalog,
        private WebstoreProposalService $proposals,
        private ActivityLogService $activityLog,
        private WebstoreMatcher $matcher,
    ) {}

    /** @return array<string, mixed> */
    public function publish(?User $user): array
    {
        $batch = $this->proposals->openBatch();
        $approved = $batch?->changes()->where('status', WebstoreChange::APPROVED)->orderBy('id')->get() ?? collect();
        if (! $batch || $approved->isEmpty()) {
            throw ValidationException::withMessages(['publish' => ['Aucune ligne approuvée à publier.']]);
        }
        $settings = WebstoreSetting::current();
        @set_time_limit(900);

        $photosReachable = $this->client->isPublicUrl(asset('storage/products/check.jpg'));
        $result = ['published' => 0, 'failed' => 0, 'warnings' => 0, 'stopped' => null];

        // 1. Conflict check of the updates against the site as it is now.
        $updates = $approved->where('action', WebstoreChange::UPDATE);
        $ready = $approved->where('action', WebstoreChange::CREATE)->values();
        $local = WebstoreProduct::query()->whereIn('woo_id', $updates->pluck('woo_id'))->get()->keyBy('woo_id');
        try {
            foreach ($updates->chunk(100) as $chunk) {
                $site = $this->client->productsByIds($settings, $chunk->pluck('woo_id')->all());
                foreach ($chunk as $change) {
                    $problem = $this->conflict($site[$change->woo_id] ?? null, $local->get($change->woo_id));
                    if ($problem !== null) {
                        $this->fail($change, $problem, $result);
                    } else {
                        $ready->push($change);
                    }
                }
            }
        } catch (ValidationException $e) {
            return $this->finish($batch, $settings, $user, ['stopped' => $this->message($e)] + $result);
        }

        // 2. Sending, by small requests.
        foreach ($ready->chunk(WooCommerceClient::WRITE_CHUNK) as $chunk) {
            $creates = $chunk->where('action', WebstoreChange::CREATE)->values();
            $changes = $chunk->where('action', WebstoreChange::UPDATE)->values();
            $warnings = [];
            $createPayload = $creates->map(function (WebstoreChange $c) use ($settings, $photosReachable, &$warnings) {
                return $this->createPayload($c, $settings->create_status ?: 'draft', $photosReachable, $warnings);
            })->all();
            $updatePayload = $changes->map(function (WebstoreChange $c) use ($photosReachable, &$warnings) {
                return $this->updatePayload($c, $photosReachable, $warnings);
            })->all();

            try {
                $answer = $this->client->batch($settings, $createPayload, $updatePayload);
            } catch (ValidationException $e) {
                foreach ($chunk as $change) {
                    $this->fail($change, $this->message($e), $result);
                }
                $result['stopped'] = $this->message($e);
                break;
            }

            foreach ($creates as $i => $change) {
                $this->record($change, $answer['create'][$i] ?? null, $warnings[$change->id] ?? null, $result);
            }
            foreach ($changes as $i => $change) {
                $this->record($change, $answer['update'][$i] ?? null, $warnings[$change->id] ?? null, $result);
            }
        }

        return $this->finish($batch, $settings, $user, $result);
    }

    /** Why an update must not be sent, or null when the site still matches the read catalogue. */
    private function conflict(?array $site, ?WebstoreProduct $local): ?string
    {
        if ($site === null) {
            return 'Produit introuvable sur le site (supprimé ?) : relisez le catalogue puis recalculez.';
        }
        $modified = $site['date_modified_gmt'] ?? null;
        if ($modified && $local?->woo_modified_at && Carbon::parse($modified, 'UTC')->gt($local->woo_modified_at->copy()->addSecond())) {
            return 'Produit modifié sur le site depuis la lecture du catalogue : relisez le catalogue puis recalculez pour ne pas écraser cette modification.';
        }

        return null;
    }

    /**
     * Availability as WooCommerce expects it: the quantity is managed when the
     * shop has stock; "on backorder" / "out of stock" are set as such.
     *
     * @param  array<string, mixed>  $proposed
     * @return array<string, mixed>
     */
    private function stockPayload(array $proposed): array
    {
        return match ($proposed['stock_status'] ?? null) {
            'instock' => ['manage_stock' => true, 'stock_quantity' => (int) ($proposed['stock_quantity'] ?? 0), 'backorders' => 'no'],
            'onbackorder' => ['manage_stock' => false, 'stock_status' => 'onbackorder'],
            default => ['manage_stock' => false, 'stock_status' => 'outofstock'],
        };
    }

    /**
     * The approved price becomes the regular price; a promotional price would
     * hide it on the site, so it is removed.
     *
     * @return array<string, string>
     */
    private function pricePayload(WebstoreChange $change): array
    {
        return ['regular_price' => number_format((float) $change->finalPrice(), 2, '.', ''), 'sale_price' => ''];
    }

    /**
     * @param  array<int, string>  $warnings
     * @return array<int, array{src: string}>
     */
    private function imagesPayload(WebstoreChange $change, bool $reachable, array &$warnings): array
    {
        $images = $change->proposed['images'] ?? [];
        if ($images === []) {
            return [];
        }
        if (! $reachable) {
            $warnings[$change->id] = 'Photos non envoyées : le site ne peut pas les télécharger (adresse du POS non publique — APP_URL).';

            return [];
        }

        return array_map(fn ($img) => ['src' => (string) $img['url']], $images);
    }

    /**
     * @param  array<int, string>  $warnings
     * @return array<string, mixed>
     */
    private function updatePayload(WebstoreChange $change, bool $photosReachable, array &$warnings): array
    {
        $payload = ['id' => $change->woo_id];
        if (in_array('sku', $change->fields, true) && ! empty($change->proposed['sku'])) {
            $payload['sku'] = (string) $change->proposed['sku'];
        }
        if (in_array('price', $change->fields, true)) {
            $payload += $this->pricePayload($change);
        }
        if (in_array('stock', $change->fields, true)) {
            $payload += $this->stockPayload($change->proposed);
        }
        if (in_array('images', $change->fields, true) && ($images = $this->imagesPayload($change, $photosReachable, $warnings)) !== []) {
            $payload['images'] = $images;
        }

        return $payload;
    }

    /**
     * @param  array<int, string>  $warnings
     * @return array<string, mixed>
     */
    private function createPayload(WebstoreChange $change, string $status, bool $photosReachable, array &$warnings): array
    {
        $p = $change->proposed;
        $attributes = [];
        if (! empty($p['brand'])) {
            $attributes[] = ['name' => 'Marque', 'options' => [(string) $p['brand']], 'visible' => true];
        }
        foreach ($p['attributes'] ?? [] as $name => $value) {
            $attributes[] = ['name' => (string) $name, 'options' => [(string) $value], 'visible' => true];
        }

        return array_filter([
            'name' => (string) ($p['name'] ?? $p['sku']),
            'type' => 'simple',
            'status' => $status,
            'sku' => (string) $p['sku'],
            'description' => (string) ($p['description'] ?? ''),
            'attributes' => $attributes,
            'images' => $this->imagesPayload($change, $photosReachable, $warnings),
        ], fn ($v) => $v !== [] && $v !== '') + $this->pricePayload($change) + $this->stockPayload($p);
    }

    /**
     * @param  array<string, int|string|null>  $result
     */
    private function record(WebstoreChange $change, mixed $answer, ?string $warning, array &$result): void
    {
        if (! is_array($answer) || isset($answer['error']) || ! isset($answer['id'])) {
            $error = is_array($answer) && isset($answer['error']['message'])
                ? 'Refusé par le site : '.$this->clean((string) $answer['error']['message'])
                : 'Réponse du site incomplète pour ce produit.';
            $this->fail($change, $error, $result);

            return;
        }

        $row = $this->catalog->row($answer);
        $local = WebstoreProduct::query()->firstOrNew(['woo_id' => $row['woo_id']]);
        $local->fill(array_filter($row, fn ($v) => $v !== null) + [
            'match_status' => WebstoreProduct::MATCHED,
            'product_id' => $change->product_id,
        ]);
        if (! $local->exists && ! $local->name) {
            $local->name = $change->proposed['name'] ?? '(sans nom)';
        }
        $local->save();
        if ($change->action === WebstoreChange::CREATE && $change->product_id) {
            $this->matcher->linkCreated($change->product_id, $row['woo_id']);
        }

        $change->forceFill([
            'status' => WebstoreChange::PUBLISHED,
            'woo_id' => $row['woo_id'],
            'published_at' => now(),
            'error' => $warning,
        ])->save();
        $result['published']++;
        if ($warning) {
            $result['warnings']++;
        }
    }

    /** @param  array<string, int|string|null>  $result */
    private function fail(WebstoreChange $change, string $error, array &$result): void
    {
        $change->forceFill(['status' => WebstoreChange::FAILED, 'error' => mb_substr($error, 0, 1000)])->save();
        $result['failed']++;
    }

    /**
     * @param  array<string, int|string|null>  $result
     * @return array<string, mixed>
     */
    private function finish(WebstoreBatch $batch, WebstoreSetting $settings, ?User $user, array $result): array
    {
        if ($result['published'] > 0) {
            $settings->forceFill(['last_publish_at' => now()])->save();
        }
        $this->activityLog->logWebstoreDecision(
            $batch->id,
            "publication : {$result['published']} produit(s) publié(s), {$result['failed']} échec(s)".($result['stopped'] ? ' — arrêtée : '.$result['stopped'] : ''),
            ['decision' => 'publish'] + $result,
            $user?->id,
            $user?->name,
        );

        return $result + $this->proposals->batchInfo($batch->fresh());
    }

    private function message(ValidationException $e): string
    {
        return (string) (collect($e->errors())->flatten()->first() ?? $e->getMessage());
    }

    /** Messages from the site are shown in the page: plain text only. */
    private function clean(string $text): string
    {
        return mb_substr(trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5)), 0, 500);
    }
}
