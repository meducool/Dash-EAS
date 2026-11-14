<?php

namespace App\Domain\Products;

use App\Models\Stock;

/**
 * Server-side twin of the front's parseTyreDescription()
 * (front/src/app/core/utils/tyre-description.ts): reads a tyre label such as
 * "MICHELIN 205/55R16 91V PRIMACY 4" and extracts dimensions, load / speed
 * indexes, XL, run flat, season, OEM markings, brand and profile.
 *
 * Pure: brands (and their known profiles) are passed in, unknown parts are left out.
 */
class TyreDescriptionParser
{
    /** 205/55R16, 205/55 ZR16, 225/45ZR18, 195/70R15C, LT265/70R17, 31x10.50R15, 205/55-16 */
    // LT / P prefix only as a word of its own start ("P195/65R15"), never the last letter of a brand ("…P 195/65R15").
    private const DIMENSION = '/(?<![\d.,])(?:(?<![\p{L}\d])(?:LT|P))?\s*(\d{2,3}(?:[.,]\d{1,2})?)\s*[\/xX]\s*(\d{1,3}(?:[.,]\d{1,2})?)\s*(?:Z?R|-)\s*F?\s*(\d{2}(?:[.,]\d)?)\s*C?\b/i';

    /** 104H, 91V, 112/110R, 95 Y — right after the dimension. */
    private const LOAD_SPEED = '/^\s*(\d{2,3})(?:\/\d{2,3})?\s*([A-HJ-NP-WYZ]|ZR)\b/i';

    private const REINFORCED = '/\b(XL|EXTRA\s*LOAD|REINF(?:ORCED)?|RENFORC[EÉ])\b/iu';

    private const RUNFLAT = '/\b(RFT|ROF|RUN\s*-?\s*FLAT|ZP|SSR|DSST|EMT|HRS)\b/i';

    private const SEASONS = [
        'all_season' => '/\b(ALL\s*-?\s*SEASONS?|4\s*-?\s*SAISONS?|QUATRE\s*SAISONS|4S)\b/iu',
        'winter' => '/\b(WINTER|HIVER|SNOW|NEIGE|3PMSF)\b/iu',
        'summer' => '/(?<![\p{L}\d])(SUMMER|[EÉ]T[EÉ])(?![\p{L}\d])/iu',
    ];

    private const MARKINGS = '/(?<![\w*])(MOE?S?|MO1|AOE?|RO[12]|N[0-9]|NF0|NC0|PNCS|SEAL(?:INSIDE)?|ELT|LR|VOL|J|T[0-2]|TO|K[1-3]|\*)(?![\w*])/i';

    private const NOISE_WORDS = '/\b(PNEU|PNEUS|TYRE|TIRE|TL|TT|M\+S|M\/S|DOT\s*\d{4})\b/i';

    /**
     * @param  array<int, array{id:int, name:string, code?:?string}>  $brands
     * @param  array<int, array<int, string>>  $profilesByBrand  brand id → known profile names
     * @return array{width?:float, height?:float, diameter?:float, load_index?:string, speed_index?:string,
     *     reinforced?:bool, runflat?:bool, season?:string, marking?:string, brand_id?:int, profile?:string}
     */
    public function parse(?string $text, array $brands = [], array $profilesByBrand = []): array
    {
        $rest = ' '.trim((string) preg_replace('/\s+/u', ' ', (string) $text)).' ';
        $result = [];
        if (trim($rest) === '') {
            return $result;
        }

        if (preg_match(self::DIMENSION, $rest, $m, PREG_OFFSET_CAPTURE)) {
            $result['width'] = $this->number($m[1][0]);
            $result['height'] = $this->number($m[2][0]);
            $result['diameter'] = $this->number($m[3][0]);
            $start = $m[0][1];
            $after = substr($rest, $start + strlen($m[0][0]));
            if (preg_match(self::LOAD_SPEED, $after, $ls)) {
                $result['load_index'] = $ls[1];
                $result['speed_index'] = strtoupper($ls[2]) === 'ZR' ? 'W' : strtoupper($ls[2]);
                $after = substr($after, strlen($ls[0]));
            }
            $rest = substr($rest, 0, $start).' '.$after;
        }

        if (preg_match(self::REINFORCED, $rest)) {
            $result['reinforced'] = true;
            $rest = (string) preg_replace(self::REINFORCED, ' ', $rest);
        }
        if (preg_match(self::RUNFLAT, $rest)) {
            $result['runflat'] = true;
            $rest = (string) preg_replace(self::RUNFLAT, ' ', $rest);
        }
        foreach (self::SEASONS as $season => $re) {
            if (preg_match($re, $rest)) {
                $result['season'] = $season;
                $rest = (string) preg_replace($re, ' ', $rest);
                break;
            }
        }

        // Brand: the longest brand name found as a whole word ("BF GOODRICH" before "GOODRICH").
        $brand = $this->findBrand($rest, $brands);
        if ($brand) {
            $result['brand_id'] = (int) $brand['id'];
            $rest = (string) preg_replace($this->wordPattern($brand['name']), ' ', $rest);
        }

        $rest = (string) preg_replace(self::NOISE_WORDS, ' ', $rest);

        // A known profile of the brand wins over the leftover text (longest first).
        if ($brand && ! empty($profilesByBrand[$brand['id']])) {
            $known = $profilesByBrand[$brand['id']];
            usort($known, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
            foreach ($known as $profile) {
                if (trim($profile) !== '' && preg_match($this->wordPattern($profile), $rest)) {
                    $result['profile'] = $profile;
                    $rest = (string) preg_replace($this->wordPattern($profile), ' ', $rest);
                    break;
                }
            }
        }

        $markings = [];
        $rest = (string) preg_replace_callback(self::MARKINGS, function ($m) use (&$markings) {
            $markings[] = strtoupper($m[0]);

            return ' ';
        }, $rest);
        if ($markings) {
            $result['marking'] = implode(', ', array_unique($markings));
        }

        if (! isset($result['profile'])) {
            $profile = trim((string) preg_replace(['/\s+/u', '/^[\s\-–—|,;:]+|[\s\-–—|,;:]+$/u'], [' ', ''], $rest));
            if ($profile !== '') {
                $result['profile'] = $profile;
            }
        }

        return $result;
    }

    /**
     * Dimensions typed in a "Dimension" column: "205/55R16 91V", or the shop's
     * shorthand "2055516" (read like the stock search, {@see Stock::parseSearchQuery()}).
     *
     * @return array{width?:float, height?:float, diameter?:float, load_index?:string, speed_index?:string}
     */
    public function parseDimension(?string $text): array
    {
        $parsed = $this->parse($text);
        if (isset($parsed['width'])) {
            return array_intersect_key($parsed, array_flip(['width', 'height', 'diameter', 'load_index', 'speed_index']));
        }

        $compact = Stock::parseSearchQuery(strtoupper(trim((string) $text)));
        if ($compact['width'] && $compact['diameter']) {
            $result = ['width' => (float) $compact['width'], 'height' => $compact['height'] !== null ? (float) $compact['height'] : null, 'diameter' => (float) $compact['diameter']];
            foreach ($compact['text'] as $token) {
                if (preg_match('/^(\d{2,3})([A-HJ-NP-WYZ])$/i', $token, $m)) {
                    $result['load_index'] = $m[1];
                    $result['speed_index'] = strtoupper($m[2]);
                }
            }

            return array_filter($result, fn ($v) => $v !== null);
        }

        return [];
    }

    /**
     * Load / speed indexes typed in their own column ("IC/IV"): "91V", "91 V",
     * "91/V", "112/110R", "94V XL" (XL / RFT there also count).
     *
     * @return array{load_index?:string, speed_index?:string, reinforced?:bool, runflat?:bool}
     */
    public function parseIndexes(?string $text): array
    {
        $text = strtoupper(trim((string) $text));
        if ($text === '') {
            return [];
        }

        $result = [];
        if (preg_match('/^(\d{2,3})(?:\/\d{2,3})?\s*\/?\s*([A-HJ-NP-WYZ])(?![A-Z])/', $text, $m)) {
            $result['load_index'] = $m[1];
            $result['speed_index'] = $m[2];
        }
        if (preg_match(self::REINFORCED, $text)) {
            $result['reinforced'] = true;
        }
        if (preg_match(self::RUNFLAT, $text)) {
            $result['runflat'] = true;
        }

        return $result;
    }

    /**
     * @param  array<int, array{id:int, name:string, code?:?string}>  $brands
     * @return array{id:int, name:string}|null
     */
    public function findBrand(string $text, array $brands): ?array
    {
        $candidates = array_filter($brands, fn ($b) => trim((string) ($b['name'] ?? '')) !== '');
        usort($candidates, fn ($a, $b) => mb_strlen($b['name']) <=> mb_strlen($a['name']));
        foreach ($candidates as $brand) {
            if (preg_match($this->wordPattern($brand['name']), $text)) {
                return $brand;
            }
        }

        return null;
    }

    private function wordPattern(string $word): string
    {
        $escaped = preg_quote(trim($word), '/');
        $escaped = (string) preg_replace('/\\\\? +/', '\\s+', $escaped);

        return '/(?<![\p{L}\d])'.$escaped.'(?![\p{L}\d])/iu';
    }

    private function number(string $value): float
    {
        return (float) str_replace(',', '.', $value);
    }
}
