<?php

namespace App\Domain\Products;

/**
 * Makes sure a tyre description carries its load and speed indexes — PHP twin
 * of the front's withTyreIndexes() (core/utils/tyre-description.ts):
 * "MICHELIN 215/45R16 PRIMACY 3" + 91 / W → "MICHELIN 215/45R16 91W PRIMACY 3".
 */
class TyreDescriptionCompleter
{
    /** Indexes added / description built. */
    public const ADDED = 'added';

    /** Description empty: built from the characteristics. */
    public const BUILT = 'built';

    /** Already complete, or nothing to add (indexes unknown). */
    public const UNCHANGED = 'unchanged';

    /** The description has other indexes than the product: never changed, to check by hand. */
    public const CONFLICT = 'conflict';

    /** No readable dimension in the text (reference, "18.4-26", "DATE 2021"): left alone, to check by hand. */
    public const NO_DIMENSION = 'no_dimension';

    /** The product sheet holds indexes that cannot be right ("107WW", "16PR"): nothing written, to fix by hand. */
    public const INVALID = 'invalid';

    /** 91, 107/105 — and one speed letter (no I, O, X). */
    private const LOAD = '/^\d{2,3}(?:\/\d{2,3})?$/';

    private const SPEED = '/^[A-HJ-NP-WYZ]$/';

    public function __construct(private TyreDescriptionParser $parser) {}

    /**
     * @param  array{width?: mixed, height?: mixed, diameter?: mixed, load_index?: ?string, speed_index?: ?string, brand?: ?string, profile?: ?string}  $t
     * @return array{status: string, description: string, written?: string}
     */
    public function complete(?string $text, array $t): array
    {
        $desc = trim((string) $text);
        $load = trim((string) ($t['load_index'] ?? ''));
        $speed = mb_strtoupper(trim((string) ($t['speed_index'] ?? '')));
        if ($load === '' || $speed === '') {
            return ['status' => self::UNCHANGED, 'description' => $desc];
        }
        if (! preg_match(self::LOAD, $load) || ! preg_match(self::SPEED, $speed)) {
            return ['status' => self::INVALID, 'description' => $desc, 'written' => $load.$speed];
        }
        $indexes = $load.$speed;
        $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
        $dimension = ! empty($t['width']) && ! empty($t['diameter'])
            ? $num($t['width']).(! empty($t['height']) ? '/'.$num($t['height']) : '').'R'.$num($t['diameter'])
            : '';

        if ($desc === '') {
            $parts = array_filter([$t['brand'] ?? null, $dimension, $indexes, $t['profile'] ?? null], fn ($p) => trim((string) $p) !== '');

            return ['status' => self::BUILT, 'description' => mb_strtoupper(implode(' ', $parts))];
        }

        $parsed = $this->parser->parse($desc);
        if (! empty($parsed['load_index']) && ! empty($parsed['speed_index'])) {
            $written = $parsed['load_index'].$parsed['speed_index'];
            $same = $parsed['load_index'] === explode('/', $load)[0] && mb_strtoupper($parsed['speed_index']) === $speed;

            return $same
                ? ['status' => self::UNCHANGED, 'description' => $desc]
                : ['status' => self::CONFLICT, 'description' => $desc, 'written' => $written];
        }
        // Indexes written further in the text ("… 8PR PT825 107/105R"): same load index → already there.
        $first = explode('/', $load)[0];
        if (preg_match('/(?<![\d\/])'.preg_quote($first, '/').'(?:\/\d{2,3})?\s*([A-HJ-NP-WYZ])\b/i', $desc, $w)) {
            return mb_strtoupper($w[1]) === $speed
                ? ['status' => self::UNCHANGED, 'description' => $desc]
                : ['status' => self::CONFLICT, 'description' => $desc, 'written' => $w[0]];
        }

        if (! preg_match(TyreDescriptionParser::DIMENSION, $desc, $m, PREG_OFFSET_CAPTURE)) {
            return ['status' => self::NO_DIMENSION, 'description' => $desc];
        }
        $end = $m[0][1] + strlen($m[0][0]);
        $after = substr($desc, $end);
        // Load numbers already right after the dimension ("245/70R17.5 143/141 …"): to check by hand.
        if (preg_match('/^\s*(\d{2,3}(?:\/\d{2,3})?)\b/', $after, $n)) {
            return ['status' => self::CONFLICT, 'description' => $desc, 'written' => $n[1]];
        }

        return ['status' => self::ADDED, 'description' => trim(rtrim(substr($desc, 0, $end)).' '.$indexes.' '.ltrim($after))];
    }
}
