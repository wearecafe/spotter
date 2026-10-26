<?php

namespace App\Support;

/**
 * Loads are free text ("75 kg", "135 lb", "BW", "2 × 16 kg"). This reads the
 * first number and unit so progression rules and PR detection can do maths on them.
 */
class Load
{
    /**
     * @return array{value: float|null, unit: string|null}
     */
    public static function parse(?string $load): array
    {
        $m = self::match($load);

        if ($m === null) {
            return ['value' => null, 'unit' => null];
        }

        $unit = $m['unit'] !== '' ? (str_starts_with(strtolower($m['unit']), 'kg') ? 'kg' : 'lb') : null;

        return ['value' => (float) str_replace(',', '.', $m['number']), 'unit' => $unit];
    }

    /**
     * The number that carries a unit ("16" in "2 × 16 kg"), or else the first number.
     *
     * @return array{number: string, unit: string, offset: int}|null
     */
    protected static function match(?string $load): ?array
    {
        if ($load === null) {
            return null;
        }

        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(kgs?|lbs?)\b/i', $load, $m, PREG_OFFSET_CAPTURE)) {
            return ['number' => $m[1][0], 'unit' => $m[2][0], 'offset' => $m[1][1]];
        }

        if (preg_match('/\d+(?:[.,]\d+)?/', $load, $m, PREG_OFFSET_CAPTURE)) {
            return ['number' => $m[0][0], 'unit' => '', 'offset' => $m[0][1]];
        }

        return null;
    }

    /**
     * Value in kilograms, for comparing loads logged in different units.
     */
    public static function toKg(?string $load): ?float
    {
        ['value' => $value, 'unit' => $unit] = self::parse($load);

        if ($value === null) {
            return null;
        }

        return $unit === 'lb' ? $value * 0.45359237 : $value;
    }

    /**
     * Add a step ("2.5 kg", "5", "-10%") to a load, keeping the load's own unit and wording.
     */
    public static function add(string $load, string $step): string
    {
        ['value' => $value, 'unit' => $unit] = self::parse($load);

        if ($value === null) {
            return $load;
        }

        $step = trim($step);

        if (preg_match('/^([+-]?\d+(?:[.,]\d+)?)\s*%$/', $step, $m)) {
            $new = $value * (1 + (float) str_replace(',', '.', $m[1]) / 100);
            $new = round($new / ($unit === 'lb' ? 5 : 2.5)) * ($unit === 'lb' ? 5 : 2.5);
        } else {
            $new = $value + (self::parse($step)['value'] ?? 0) * (str_starts_with($step, '-') ? -1 : 1);
        }

        $formatted = rtrim(rtrim(number_format(max($new, 0), 2, '.', ''), '0'), '.');

        // Replace only the load's number so text like "2 × 16 kg" keeps its shape.
        $m = self::match($load);

        return substr_replace($load, $formatted, $m['offset'], strlen($m['number']));
    }

    /**
     * Default step for a load rule when the coach hasn't set one.
     */
    public static function defaultStep(?string $load): string
    {
        return self::parse($load)['unit'] === 'lb' ? '5 lb' : '2.5 kg';
    }

    /**
     * Lowest number in a rep or RPE target: "8-10" -> 8, "7" -> 7.
     */
    public static function low(?string $target): ?float
    {
        return preg_match('/\d+(?:[.,]\d+)?/', (string) $target, $m) ? (float) str_replace(',', '.', $m[0]) : null;
    }

    /**
     * Highest number in a rep or RPE target: "7-8" -> 8, "7" -> 7.
     */
    public static function high(?string $target): ?float
    {
        preg_match_all('/\d+(?:[.,]\d+)?/', (string) $target, $m);

        return $m[0] === [] ? null : (float) str_replace(',', '.', end($m[0]));
    }
}
