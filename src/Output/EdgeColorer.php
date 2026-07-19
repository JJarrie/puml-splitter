<?php

declare(strict_types=1);

namespace PumlSplitter\Output;

/**
 * Deterministic per-entity edge colours (plan §7bis): hue is the golden angle
 * (137.508°) times the entity's alphabetical index, mod 360°, at fixed 65%
 * saturation / 40% lightness, emitted as hex.
 *
 * The index is deliberately *diagram-local*: callers pass only the sorted key
 * list relevant to the one file being rendered (a cluster's own node aliases,
 * or its own edge-pair keys), never a global, cross-file registry. A class's
 * colour must depend only on its alphabetical position among its diagram's
 * own entities — adding or removing classes in an unrelated cluster must
 * never shift colours here (plan §9 stability requirement).
 */
final class EdgeColorer
{
    private const GOLDEN_ANGLE = 137.508;
    private const SATURATION = 65.0;
    private const LIGHTNESS = 40.0;

    /**
     * @param list<string> $sortedKeys keys for one diagram only, already sorted (SORT_STRING)
     *
     * @return array<string, string> key => "#RRGGBB"
     */
    public static function palette(array $sortedKeys): array
    {
        $colors = [];
        foreach ($sortedKeys as $index => $key) {
            $colors[$key] = self::colorForIndex($index);
        }

        return $colors;
    }

    /**
     * Pure function of the index alone: same index, anywhere, always yields
     * the same colour. No floating-point ambiguity across platforms — the
     * arithmetic is plain IEEE-754 double math with no locale dependency.
     */
    public static function colorForIndex(int $index): string
    {
        $hue = fmod($index * self::GOLDEN_ANGLE, 360.0);

        return self::hslToHex($hue, self::SATURATION, self::LIGHTNESS);
    }

    private static function hslToHex(float $hue, float $saturationPercent, float $lightnessPercent): string
    {
        $s = $saturationPercent / 100;
        $l = $lightnessPercent / 100;
        $c = (1 - abs(2 * $l - 1)) * $s;
        $hPrime = $hue / 60;
        $x = $c * (1 - abs(fmod($hPrime, 2) - 1));

        [$r1, $g1, $b1] = match (true) {
            $hPrime < 1 => [$c, $x, 0.0],
            $hPrime < 2 => [$x, $c, 0.0],
            $hPrime < 3 => [0.0, $c, $x],
            $hPrime < 4 => [0.0, $x, $c],
            $hPrime < 5 => [$x, 0.0, $c],
            default => [$c, 0.0, $x],
        };

        $m = $l - $c / 2;

        return sprintf(
            '#%02X%02X%02X',
            (int) round(($r1 + $m) * 255),
            (int) round(($g1 + $m) * 255),
            (int) round(($b1 + $m) * 255),
        );
    }
}
