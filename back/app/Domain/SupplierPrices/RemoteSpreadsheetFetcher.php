<?php

namespace App\Domain\SupplierPrices;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Throwable;

/**
 * Downloads a price list published online (Google Sheets, OneDrive /
 * SharePoint, Dropbox or any direct link) — public links only.
 *
 * SSRF guard: http(s) only, and every host (initial URL and each redirect)
 * must resolve to public addresses — no loopback, private, link-local or
 * reserved ranges, so the server cannot be used to reach the internal network.
 */
class RemoteSpreadsheetFetcher
{
    public const MAX_BYTES = 15 * 1024 * 1024;

    /**
     * @return array{contents: string, extension: string}
     */
    public function fetch(string $url): array
    {
        $url = $this->directDownloadUrl(trim($url));
        $this->assertPublic($url);

        try {
            $response = Http::timeout(30)
                ->withOptions([
                    'allow_redirects' => [
                        'max' => 5,
                        'protocols' => ['http', 'https'],
                        'on_redirect' => function (RequestInterface $request, ResponseInterface $response, UriInterface $uri) {
                            $this->assertPublic((string) $uri);
                        },
                    ],
                ])
                ->get($url);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw $this->fail('Le lien n\'a pas pu être lu ('.class_basename($e).').');
        }

        if (! $response->successful()) {
            throw $this->fail("Le lien a répondu avec l'erreur HTTP {$response->status()} (fichier non public ?).");
        }

        $contents = $response->body();
        if (strlen($contents) > self::MAX_BYTES) {
            throw $this->fail('Fichier distant trop volumineux (maximum 15 Mo).');
        }
        if (stripos(substr($contents, 0, 1024), '<html') !== false || stripos(substr($contents, 0, 1024), '<!doctype html') !== false) {
            throw $this->fail('Le lien renvoie une page web et non un fichier Excel : vérifiez qu\'il est partagé publiquement.');
        }

        return ['contents' => $contents, 'extension' => $this->extensionOf($contents)];
    }

    /** Share links turned into direct-download links. */
    public function directDownloadUrl(string $url): string
    {
        // Google Sheets: …/spreadsheets/d/{id}/edit#gid=… → …/export?format=xlsx
        if (preg_match('#^https://docs\.google\.com/spreadsheets/d/([A-Za-z0-9_-]+)#', $url, $m)) {
            $gid = preg_match('/[#&?]gid=(\d+)/', $url, $g) ? '&gid='.$g[1] : '';

            return "https://docs.google.com/spreadsheets/d/{$m[1]}/export?format=xlsx{$gid}";
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        // OneDrive / SharePoint share links download with download=1.
        if (str_ends_with($host, 'sharepoint.com') || str_ends_with($host, 'onedrive.live.com') || $host === '1drv.ms') {
            return str_contains($url, 'download=1') ? $url : $url.(str_contains($url, '?') ? '&' : '?').'download=1';
        }
        // Dropbox: dl=0 → dl=1.
        if (str_ends_with($host, 'dropbox.com')) {
            $url = (string) preg_replace('/([?&])dl=0\b/', '$1dl=1', $url);

            return str_contains($url, 'dl=1') ? $url : $url.(str_contains($url, '?') ? '&' : '?').'dl=1';
        }

        return $url;
    }

    public function assertPublic(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw $this->fail('Lien invalide : une adresse http(s) est attendue.');
        }

        $host = trim($host, '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolve($host);
        if (! $ips) {
            throw $this->fail("Adresse introuvable : {$host}.");
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw $this->fail('Ce lien pointe vers une adresse interne : refusé.');
            }
        }
    }

    /**
     * @return array<int, string>
     */
    protected function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        $ips = [];
        foreach ($records as $record) {
            $ips[] = $record['ip'] ?? $record['ipv6'] ?? null;
        }
        $ips = array_values(array_filter($ips));

        return $ips ?: (gethostbynamel($host) ?: []);
    }

    private function extensionOf(string $contents): string
    {
        if (str_starts_with($contents, "PK\x03\x04")) {
            return str_contains(substr($contents, 0, 200), 'opendocument') ? 'ods' : 'xlsx';
        }
        if (str_starts_with($contents, "\xD0\xCF\x11\xE0")) {
            return 'xls';
        }

        return 'csv';
    }

    private function fail(string $message): ValidationException
    {
        return ValidationException::withMessages(['source_url' => [$message]]);
    }
}
