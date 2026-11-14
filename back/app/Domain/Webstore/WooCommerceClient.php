<?php

namespace App\Domain\Webstore;

use App\Models\WebstoreSetting;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * WooCommerce REST API (wp-json/wc/v3) — read-only calls for now.
 *
 * Security: HTTPS only (the API keys go in a Basic auth header), the host must
 * resolve to public addresses (no internal network — SSRF) and redirects are
 * never followed, so the keys cannot be sent to another host.
 */
class WooCommerceClient
{
    public const PER_PAGE = 100;

    private const API_PATH = '/wp-json/wc/v3/';

    /** Fields read from each product (keeps the answers small). */
    private const PRODUCT_FIELDS = 'id,sku,name,type,status,price,regular_price,sale_price,stock_status,stock_quantity,images,permalink,date_modified_gmt';

    /** Shop address cleaned up: https, no trailing slash, no path to the API. */
    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url !== '' && ! preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'https://'.$url;
        }
        $url = (string) preg_replace('#/wp-json(/.*)?$#i', '', $url);

        return rtrim($url, '/');
    }

    /**
     * One page of products.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, total_pages: int}
     */
    public function products(WebstoreSetting $settings, int $page = 1, int $perPage = self::PER_PAGE): array
    {
        $response = $this->request($settings, 'get', 'products', [
            'page' => $page,
            'per_page' => $perPage,
            'orderby' => 'id',
            'order' => 'asc',
            'status' => 'any',
            '_fields' => self::PRODUCT_FIELDS,
        ]);

        $items = $response->json();
        if (! is_array($items) || ($items !== [] && ! array_is_list($items))) {
            throw $this->fail('Réponse inattendue du site : ce n\'est pas l\'API WooCommerce.');
        }

        return [
            'items' => $items,
            'total' => (int) $response->header('X-WP-Total'),
            'total_pages' => max(1, (int) $response->header('X-WP-TotalPages')),
        ];
    }

    /**
     * Checks the address and the keys with a tiny read.
     *
     * @return array{products_total: int}
     */
    public function test(WebstoreSetting $settings): array
    {
        return ['products_total' => $this->products($settings, 1, 1)['total']];
    }

    /** Products sent per write request (photos are downloaded by the site: keep requests short). */
    public const WRITE_CHUNK = 20;

    /** Fields read back to check a product before writing it (conflict check). */
    private const CHECK_FIELDS = 'id,sku,name,type,status,price,regular_price,sale_price,stock_status,stock_quantity,images,permalink,date_modified_gmt';

    /**
     * Current values of some products (max 100 ids), to check nobody changed them on the site.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<string, mixed>> keyed by id
     */
    public function productsByIds(WebstoreSetting $settings, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $items = $this->request($settings, 'get', 'products', [
            'include' => implode(',', array_slice($ids, 0, 100)),
            'per_page' => 100,
            'status' => 'any',
            '_fields' => self::CHECK_FIELDS,
        ])->json();

        $byId = [];
        foreach (is_array($items) ? $items : [] as $item) {
            if (is_array($item) && isset($item['id'])) {
                $byId[(int) $item['id']] = $item;
            }
        }

        return $byId;
    }

    /**
     * Creates / updates products in one request (wc/v3 products/batch). The answer
     * holds, per item and in the same order, the product or {id, error}.
     *
     * @param  array<int, array<string, mixed>>  $create
     * @param  array<int, array<string, mixed>>  $update
     * @return array{create: array<int, mixed>, update: array<int, mixed>}
     */
    public function batch(WebstoreSetting $settings, array $create, array $update): array
    {
        $body = $this->request($settings, 'post', 'products/batch', [], array_filter(['create' => $create, 'update' => $update]), 180)->json();

        return [
            'create' => is_array($body['create'] ?? null) ? array_values($body['create']) : [],
            'update' => is_array($body['update'] ?? null) ? array_values($body['update']) : [],
        ];
    }

    /** True when the site can download this address (photos): http(s) on a public host. */
    public function isPublicUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = trim((string) ($parts['host'] ?? ''), '[]');
        if (! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || $host === '' || $host === 'localhost') {
            return false;
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolve($host);
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return $ips !== [];
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     */
    private function request(WebstoreSetting $settings, string $method, string $endpoint, array $query, ?array $body = null, int $timeout = 30): Response
    {
        if (! $settings->hasCredentials()) {
            throw $this->fail('Renseignez l\'adresse du site et les clés API WooCommerce.');
        }
        $base = self::normalizeUrl((string) $settings->store_url);
        $this->assertSafe($base);
        $url = $base.self::API_PATH.$endpoint;

        try {
            $client = Http::timeout($timeout)
                ->acceptJson()
                ->withBasicAuth((string) $settings->consumer_key, (string) $settings->consumer_secret)
                ->withOptions(['allow_redirects' => false]);
            $response = $method === 'get'
                ? $client->get($url, $query)
                : $client->asJson()->post($url.($query ? '?'.http_build_query($query) : ''), $body ?? []);
        } catch (Throwable $e) {
            throw $this->fail('Le site ne répond pas ('.class_basename($e).').');
        }

        if ($response->successful()) {
            return $response;
        }

        throw $this->fail(match (true) {
            $response->status() === 401, $response->status() === 403 => $method === 'get'
                ? 'Clés API refusées par le site (vérifiez la clé, le secret et leurs droits « Lecture » ou « Lecture/Écriture »).'
                : 'Écriture refusée par le site : les clés API doivent avoir les droits « Lecture/Écriture ».',
            $response->status() === 404 => 'API WooCommerce introuvable à cette adresse (WooCommerce installé ? permaliens WordPress activés ?).',
            $response->redirect() => 'Le site redirige vers une autre adresse ('.($response->header('Location') ?: '?').') : saisissez l\'adresse finale du site.',
            default => "Le site a répondu avec l'erreur HTTP {$response->status()}.",
        });
    }

    public function assertSafe(string $url): void
    {
        $parts = parse_url($url);
        $host = trim((string) ($parts['host'] ?? ''), '[]');
        if (strtolower($parts['scheme'] ?? '') !== 'https' || $host === '') {
            throw $this->fail('Adresse invalide : le site doit être en https://.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolve($host);
        if (! $ips) {
            throw $this->fail("Adresse introuvable : {$host}.");
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw $this->fail('Cette adresse pointe vers le réseau interne : refusé.');
            }
        }
    }

    /**
     * @return array<int, string>
     */
    protected function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        $ips = array_values(array_filter(array_map(fn ($r) => $r['ip'] ?? $r['ipv6'] ?? null, $records)));

        return $ips ?: (gethostbynamel($host) ?: []);
    }

    private function fail(string $message): ValidationException
    {
        return ValidationException::withMessages(['store_url' => [$message]]);
    }
}
