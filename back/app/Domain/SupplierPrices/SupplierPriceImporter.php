<?php

namespace App\Domain\SupplierPrices;

use App\Domain\Products\ProductReferenceService;
use App\Domain\Products\TyreDescriptionParser;
use App\Models\Brand;
use App\Models\BrandProfile;
use App\Models\SupplierPriceFile;
use App\Models\SupplierPriceItem;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * Reads a supplier price list (xlsx / xls / ods / csv) and replaces the lines
 * of its SupplierPriceFile: header row detected, columns mapped (auto-detected,
 * overridable per file), every line identified as brand / profile / dimensions
 * and linked to the catalogue product with the same generated reference.
 */
class SupplierPriceImporter
{
    public const MAX_ROWS = 20000;

    /** Fields a column can be mapped to, in display order. */
    public const FIELDS = ['designation', 'reference', 'brand', 'profile', 'dimension', 'indexes', 'runflat', 'price', 'availability'];

    /** Header synonyms (compared without accents, lower-case). */
    private const SYNONYMS = [
        'designation' => ['designation', 'libelle', 'description', 'article', 'produit', 'designation article', 'libelle article', 'nom', 'intitule'],
        'reference' => ['reference', 'ref', 'ref.', 'code', 'code article', 'sku', 'ref fournisseur', 'reference fournisseur', 'code produit', 'ean', 'cai'],
        'brand' => ['marque', 'brand', 'fabricant', 'manufacturer'],
        'profile' => ['profil', 'modele', 'model', 'sculpture', 'pattern', 'gamme'],
        'dimension' => ['dimension', 'dimensions', 'taille', 'size', 'dim', 'mesure', 'cote'],
        'indexes' => ['ic/iv', 'ic iv', 'icv', 'li/si', 'indice', 'indices', 'indice de charge', 'indices charge/vitesse', 'charge/vitesse', 'indice charge/vitesse', 'ic-iv'],
        'runflat' => ['runflat', 'run flat', 'run-flat', 'rft', 'rof', 'rf'],
        // Recognised only to be left aside: the selling price is computed (Prix TTC × margin), never read.
        'ignore' => ['prix de vente', 'prix vente', 'pv', 'pv ttc', 'prix public', 'prix client', 'prix conseille', 'ppc'],
        'price' => ['prix', 'price', 'tarif', 'pu', 'p.u', 'p.u.', 'prix unitaire', 'prix ttc', 'pu ttc', 'prix net', 'net', 'ttc', 'montant', 'prix achat', "prix d'achat"],
        'availability' => ['stock', 'qte', 'quantite', 'dispo', 'disponible', 'disponibilite', 'qty', 'quantity'],
    ];

    public function __construct(
        private TyreDescriptionParser $parser,
        private ProductReferenceService $references,
        private SupplierProductMatcher $matcher,
    ) {}

    /**
     * Re-reads the file stored for $file and replaces its lines.
     *
     * @param  array<string, string|null>|null  $mapping  column letters chosen by the user (null = auto-detect)
     */
    public function import(SupplierPriceFile $file, ?array $mapping = null): SupplierPriceFile
    {
        $path = $file->stored_path ? Storage::disk('local')->path($file->stored_path) : null;
        if (! $path || ! is_file($path)) {
            throw ValidationException::withMessages(['file' => ['Le fichier de ce tarif est introuvable : téléversez-le à nouveau.']]);
        }

        $rows = $this->readRows($path);
        [$headerIndex, $detected] = $this->detectHeader($rows);
        $headerRow = $headerIndex !== null ? $rows[$headerIndex] : [];
        $headers = [];
        foreach ($headerRow ?: ($rows[array_key_first($rows)] ?? []) as $letter => $value) {
            $headers[$letter] = trim((string) $value);
        }

        $mapping = $this->cleanMapping($mapping ?? $file->column_mapping ?? [], array_keys($headers));
        $mapping = $mapping ?: $detected;
        if (empty($mapping['price']) || (empty($mapping['designation']) && empty($mapping['dimension']) && empty($mapping['reference']))) {
            $message = 'Colonnes non reconnues : indiquez au moins la colonne Prix et la colonne Désignation (ou Dimension).';
            $file->update(['headers' => $headers, 'last_error' => $message]);
            // The page shows the columns (and a few rows) so the user can map them and resend.
            throw new HttpResponseException(response()->json([
                'message' => $message,
                'errors' => ['column_mapping' => [$message]],
                'headers' => $headers,
                'preview' => array_values(array_map(
                    fn ($row) => array_map(fn ($v) => is_scalar($v) ? mb_substr((string) $v, 0, 80) : '', $row),
                    array_slice($rows, 0, 8, true),
                )),
            ], 422));
        }

        $dataRows = $headerIndex !== null
            ? array_slice($rows, array_search($headerIndex, array_keys($rows), true) + 1, null, true)
            : $rows;
        if (count($dataRows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => ['Fichier trop volumineux (maximum '.number_format(self::MAX_ROWS, 0, ',', ' ').' lignes).']]);
        }

        $items = $this->references->withCache(fn () => $this->buildItems($file, $dataRows, $mapping));

        DB::transaction(function () use ($file, $items, $headers, $mapping) {
            $file->items()->delete();
            foreach (array_chunk($items, 500) as $chunk) {
                SupplierPriceItem::query()->insert($chunk);
            }
            $file->update([
                'headers' => $headers,
                'column_mapping' => $mapping,
                'rows_count' => count($items),
                'identified_count' => count(array_filter($items, fn ($i) => $i['width'] !== null)),
                'last_imported_at' => now(),
                'last_error' => null,
            ]);
        });

        return $file->fresh(['supplier:id,name']);
    }

    /**
     * @return array<int, array<string, mixed>> rows keyed by spreadsheet row number, cells by column letter
     */
    private function readRows(string $path): array
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $book = $reader->load($path);
            // The standard template's "Tarif" sheet wins (an export can open on another sheet).
            $sheet = $book->getActiveSheet();
            foreach ($book->getWorksheetIterator() as $candidate) {
                if (mb_strtolower(trim($candidate->getTitle())) === mb_strtolower(SupplierPriceTemplate::SHEET)) {
                    $sheet = $candidate;
                    break;
                }
            }

            return $sheet->toArray(null, true, false, true);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['file' => ['Fichier illisible : un fichier Excel (.xlsx, .xls, .ods) ou CSV est attendu.']]);
        }
    }

    /**
     * Header row = the first of the 20 first rows naming at least two known columns.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{0: int|null, 1: array<string, string>}
     */
    public function detectHeader(array $rows): array
    {
        $checked = 0;
        foreach ($rows as $index => $row) {
            if (++$checked > 20) {
                break;
            }
            $mapping = [];
            foreach ($row as $letter => $value) {
                $field = $this->fieldFor((string) $value);
                if ($field === null || $field === 'ignore') {
                    continue;
                }
                // Price: a "TTC" column wins over a plain "Prix" one.
                if ($field === 'price' && isset($mapping['price']) && ! str_contains($this->normalizeHeader((string) $value), 'ttc')) {
                    continue;
                }
                if ($field !== 'price' && isset($mapping[$field])) {
                    continue;
                }
                $mapping[$field] = $letter;
            }
            if (count($mapping) >= 2 && isset($mapping['price'])) {
                return [$index, $mapping];
            }
        }

        return [null, []];
    }

    private function fieldFor(string $header): ?string
    {
        $normalized = $this->normalizeHeader($header);
        if ($normalized === '' || mb_strlen($normalized) > 40) {
            return null;
        }
        foreach (self::SYNONYMS as $field => $synonyms) {
            if (in_array($normalized, $synonyms, true)) {
                return $field;
            }
        }
        // Prefix match, longest synonym first: "prix de vente ttc" is a sale price, not a "prix".
        $pairs = [];
        foreach (self::SYNONYMS as $field => $synonyms) {
            foreach ($synonyms as $synonym) {
                if (mb_strlen($synonym) >= 3) {
                    $pairs[] = [$field, $synonym];
                }
            }
        }
        usort($pairs, fn ($a, $b) => mb_strlen($b[1]) <=> mb_strlen($a[1]));
        foreach ($pairs as [$field, $synonym]) {
            if (preg_match('/^'.preg_quote($synonym, '/').'\b/u', $normalized)) {
                return $field;
            }
        }

        return null;
    }

    private function normalizeHeader(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'û' => 'u', 'ù' => 'u', 'ç' => 'c']);
        $value = (string) preg_replace('/[\s_]+/u', ' ', $value);
        $value = (string) preg_replace('/\s*\/\s*/u', '/', $value); // "IC / IV" → "ic/iv"

        return trim($value, " :*\t");
    }

    /**
     * @param  array<string, mixed>  $mapping
     * @param  array<int, string>  $letters
     * @return array<string, string>
     */
    private function cleanMapping(array $mapping, array $letters): array
    {
        $clean = [];
        foreach (self::FIELDS as $field) {
            $letter = strtoupper(trim((string) ($mapping[$field] ?? '')));
            if ($letter !== '' && preg_match('/^[A-Z]{1,3}$/', $letter)) {
                $clean[$field] = $letter;
            }
        }

        return $clean;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, string>  $mapping
     * @return array<int, array<string, mixed>>
     */
    private function buildItems(SupplierPriceFile $file, array $rows, array $mapping): array
    {
        $brands = Brand::query()->get(['id', 'name', 'code'])->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'code' => $b->code])->all();
        $profiles = [];
        foreach (BrandProfile::query()->get(['brand_id', 'name']) as $profile) {
            $profiles[$profile->brand_id][] = $profile->name;
        }

        $cell = fn (array $row, string $field) => isset($mapping[$field]) ? trim((string) ($row[$mapping[$field]] ?? '')) : '';
        $now = now();
        $items = [];

        foreach ($rows as $rowNumber => $row) {
            $price = $this->parsePrice($row[$mapping['price']] ?? null);
            if ($price === null || $price <= 0) {
                continue;
            }

            $brandCell = $cell($row, 'brand');
            $dimensionCell = $cell($row, 'dimension');
            $profileCell = $cell($row, 'profile');
            $designation = $cell($row, 'designation');
            if ($designation === '') {
                $designation = trim(implode(' ', array_filter([$brandCell, $dimensionCell, $profileCell])));
            }
            if ($designation === '') {
                $designation = $cell($row, 'reference');
            }
            if ($designation === '') {
                continue;
            }

            $brand = $brandCell !== '' ? $this->brandFromCell($brandCell, $brands) : null;
            $parsed = $this->parser->parse(
                $brand ? $designation.' '.$brand['name'] : $designation,
                $brand ? [$brand] : $brands,
                $profiles,
            );
            $brandId = $parsed['brand_id'] ?? null;
            $brandName = $brandId ? collect($brands)->firstWhere('id', $brandId)['name'] : ($brandCell !== '' ? mb_strtoupper($brandCell) : null);

            if ($dimensionCell !== '') {
                $parsed = array_merge($parsed, $this->parser->parseDimension($dimensionCell));
            }
            // IC/IV column ("91V", "91 V", "91/V", "112/110R", "94V XL"): wins over the label.
            $parsed = array_merge($parsed, $this->parser->parseIndexes($cell($row, 'indexes')));

            // A Runflat column (Oui / X / RFT…) adds to what the label says.
            if ($this->isYes($cell($row, 'runflat'))) {
                $parsed['runflat'] = true;
            }

            $profile = $parsed['profile'] ?? null;
            if ($profileCell !== '') {
                $profile = $this->canonicalProfile($profileCell, $brandId, $profiles);
            } elseif ($profile !== null && $brandName !== null) {
                // Brand typed in its own column but unknown to the catalogue: not part of the profile.
                $profile = trim((string) preg_replace('/(?<![\p{L}\d])'.preg_quote($brandName, '/').'(?![\p{L}\d])/iu', ' ', $profile)) ?: null;
            }

            $width = $parsed['width'] ?? null;
            $diameter = $parsed['diameter'] ?? null;
            $matchKey = null;
            if ($width && $diameter && $brandId) {
                $matchKey = $this->references->baseReference([
                    'type' => 'tyre',
                    'brand_id' => $brandId,
                    'profile' => $profile,
                    'tire_width' => $width,
                    'tire_height' => $parsed['height'] ?? null,
                    'tire_diameter' => $diameter,
                    'tire_load_index' => $parsed['load_index'] ?? null,
                    'tire_speed_index' => $parsed['speed_index'] ?? null,
                    'tire_runflat' => $parsed['runflat'] ?? false,
                ]);
            } elseif ($width && $diameter && $brandName) {
                $matchKey = 'X-'.ProductReferenceService::normalize($brandName.($parsed['width'] ?? '').($parsed['height'] ?? '').$diameter.($parsed['load_index'] ?? '').($parsed['speed_index'] ?? '').($profile ? '-'.$profile : ''));
            }

            $items[] = [
                'file_id' => $file->id,
                'supplier_id' => $file->supplier_id,
                'row_number' => (int) $rowNumber,
                'designation' => mb_substr($designation, 0, 500),
                'supplier_reference' => mb_substr($cell($row, 'reference'), 0, 100) ?: null,
                'brand_name' => $brandName !== null ? mb_substr($brandName, 0, 100) : null,
                'brand_id' => $brandId,
                'profile' => $profile !== null ? mb_substr($profile, 0, 150) : null,
                'width' => $width,
                'height' => $parsed['height'] ?? null,
                'diameter' => $diameter,
                'load_index' => isset($parsed['load_index']) ? mb_substr($parsed['load_index'], 0, 10) : null,
                'speed_index' => isset($parsed['speed_index']) ? mb_substr($parsed['speed_index'], 0, 5) : null,
                'runflat' => (bool) ($parsed['runflat'] ?? false),
                'reinforced' => (bool) ($parsed['reinforced'] ?? false),
                'season' => $parsed['season'] ?? null,
                'price' => round($price, 2),
                'availability' => mb_substr($cell($row, 'availability'), 0, 50) ?: null,
                'match_key' => $matchKey !== null ? mb_substr($matchKey, 0, 120) : null,
                'product_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $this->matcher->link($items);
    }

    /**
     * @param  array<int, array{id:int, name:string, code?:?string}>  $brands
     * @return array{id:int, name:string, code?:?string}|null
     */
    private function brandFromCell(string $value, array $brands): ?array
    {
        $needle = mb_strtolower(trim($value));
        foreach ($brands as $brand) {
            if (mb_strtolower(trim($brand['name'])) === $needle || ($brand['code'] && mb_strtolower(trim($brand['code'])) === $needle)) {
                return $brand;
            }
        }

        return $this->parser->findBrand(' '.$value.' ', $brands);
    }

    /**
     * @param  array<int, array<int, string>>  $profiles
     */
    private function canonicalProfile(string $value, ?int $brandId, array $profiles): string
    {
        foreach ($brandId ? ($profiles[$brandId] ?? []) : [] as $known) {
            if (mb_strtolower(trim($known)) === mb_strtolower(trim($value))) {
                return $known;
            }
        }

        return $value;
    }

    /** Oui, O, X, 1, Yes, Vrai, RF, RFT, ROF, Runflat → true. */
    private function isYes(string $value): bool
    {
        $value = str_replace(['-', ' '], '', mb_strtolower(trim($value)));

        return in_array($value, ['oui', 'o', 'x', '1', 'yes', 'y', 'vrai', 'true', 'rf', 'rft', 'rof', 'runflat', 'ssr', 'zp'], true);
    }

    /** 1234.5, "1 234,50", "1.234,50 DH", "1,234.50", "850 MAD" → float; anything else → null. */
    public function parsePrice(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        // Text other than a currency / tax word ("Colonne 2", "sur demande") is not a price.
        $words = (string) preg_replace('/\b(DH|DHS|MAD|EUR|TTC|HT|NET)\b|€/iu', '', (string) $value);
        if (preg_match('/\p{L}/u', $words)) {
            return null;
        }
        $text = (string) preg_replace('/[^\d,.\-]/u', '', (string) $value);
        if ($text === '' || ! preg_match('/\d/', $text)) {
            return null;
        }
        $lastComma = strrpos($text, ',');
        $lastDot = strrpos($text, '.');
        if ($lastComma !== false && $lastDot !== false) {
            $decimal = $lastComma > $lastDot ? ',' : '.';
            $thousands = $decimal === ',' ? '.' : ',';
            $text = str_replace([$thousands, $decimal], ['', '.'], $text);
        } elseif ($lastComma !== false) {
            // "1,234" with exactly three digits after a single comma reads as thousands only if more commas follow.
            $text = substr_count($text, ',') > 1 ? str_replace(',', '', $text) : str_replace(',', '.', $text);
        } elseif (substr_count($text, '.') > 1) {
            $text = str_replace('.', '', $text);
        }

        return is_numeric($text) ? (float) $text : null;
    }
}
