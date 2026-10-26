<?php

namespace App\Services;

use App\Models\NutritionLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Reads daily calories, macros and weight from nutrition-app exports.
 *
 * None of MacroFactor, MyFitnessPal or Lose It! offer an open API for this, so clients
 * export their data from the app and upload the file. Exports differ: MyFitnessPal's zip has
 * one row per meal, Lose It!'s food log one row per food, MacroFactor one row per day. Columns
 * are matched by name (case and units ignored) and rows are summed into one total per day.
 */
class NutritionImporter
{
    /** Normalized header names for each field we read. */
    public const COLUMNS = [
        'date' => ['date', 'day', 'logged on', 'log date', 'food log date', 'entry date'],
        'calories' => ['calories', 'calories kcal', 'energy kcal', 'energy', 'kcal', 'cals', 'total calories', 'calories consumed'],
        'protein' => ['protein', 'protein g', 'protein grams', 'total protein'],
        'carbs' => ['carbs', 'carbs g', 'carbohydrates', 'carbohydrates g', 'carbohydrate', 'carbohydrate g', 'total carbs', 'total carbohydrates'],
        'fat' => ['fat', 'fat g', 'total fat', 'total fat g', 'fats', 'fats g'],
        'weight' => ['weight', 'weight kg', 'weight lb', 'weight lbs', 'body weight', 'bodyweight', 'scale weight', 'scale weight kg', 'scale weight lbs', 'body weight kg', 'body weight lbs'],
    ];

    public const MAX_DAYS = 800;

    /**
     * Read an uploaded export (CSV, or a zip of CSVs such as MyFitnessPal's).
     *
     * @return array{source: string, days: array<string, array{calories: int|null, protein: int|null, carbs: int|null, fat: int|null, weight: float|null, weight_unit: string|null}>}
     */
    public function read(string $path, string $filename, string $source, string $defaultUnit = 'kg'): array
    {
        $csvs = Str::lower(pathinfo($filename, PATHINFO_EXTENSION)) === 'zip'
            ? $this->csvsInZip($path)
            : [$filename => (string) file_get_contents($path)];

        $days = [];
        $detected = null;

        foreach ($csvs as $name => $contents) {
            $parsed = $this->parseCsv($contents, $defaultUnit);
            $detected ??= $this->detectSource($name, $parsed['headers']);

            foreach ($parsed['days'] as $date => $day) {
                $days[$date] = $this->merge($days[$date] ?? null, $day);
            }
        }

        krsort($days);

        return [
            'source' => array_key_exists($source, NutritionLog::SOURCES) && $source !== 'manual' ? $source : ($detected ?? 'csv'),
            'days' => array_slice($days, 0, self::MAX_DAYS, true),
        ];
    }

    /**
     * Save imported days. Fields the file has overwrite that day; fields it lacks are kept.
     *
     * @param  array<string, array<string, mixed>>  $days
     * @return array{created: int, updated: int}
     */
    public function save(User $client, array $days, string $source): array
    {
        $created = $updated = 0;

        foreach ($days as $date => $day) {
            $values = array_filter([
                'calories' => $day['calories'],
                'protein' => $day['protein'],
                'carbs' => $day['carbs'],
                'fat' => $day['fat'],
                'weight' => $day['weight'],
            ], fn ($v) => $v !== null);

            if ($values === []) {
                continue;
            }

            if (isset($values['weight'])) {
                $values['weight_unit'] = $day['weight_unit'] ?? 'kg';
            }

            $log = NutritionLog::firstOrNew(['client_id' => $client->id, 'logged_on' => $date]);
            $log->exists ? $updated++ : $created++;
            $log->fill($values + ['source' => $source])->save();
        }

        return compact('created', 'updated');
    }

    /**
     * @return array{headers: array<int, string>, days: array<string, array<string, mixed>>}
     */
    public function parseCsv(string $contents, string $defaultUnit = 'kg'): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $lines = preg_split('/\r\n|\r|\n/', trim($contents)) ?: [];

        if (count($lines) < 2) {
            return ['headers' => [], 'days' => []];
        }

        $delimiter = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';
        $headers = array_map(fn ($h) => $this->normalizeHeader((string) $h), str_getcsv($lines[0], $delimiter));
        $col = [];

        foreach (self::COLUMNS as $field => $names) {
            foreach ($names as $name) {
                $index = array_search($name, $headers, true);
                if ($index !== false) {
                    $col[$field] = $index;
                    break;
                }
            }
        }

        if (! isset($col['date'])) {
            return ['headers' => $headers, 'days' => []];
        }

        $weightUnit = isset($col['weight'])
            ? (str_contains($headers[$col['weight']], 'lb') ? 'lb' : (str_contains($headers[$col['weight']], 'kg') ? 'kg' : $defaultUnit))
            : null;

        $days = [];

        foreach (array_slice($lines, 1) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $row = str_getcsv($line, $delimiter);
            $date = $this->parseDate($row[$col['date']] ?? '');

            if ($date === null) {
                continue;
            }

            $day = [
                'calories' => $this->number($row, $col['calories'] ?? null),
                'protein' => $this->number($row, $col['protein'] ?? null),
                'carbs' => $this->number($row, $col['carbs'] ?? null),
                'fat' => $this->number($row, $col['fat'] ?? null),
                'weight' => $this->number($row, $col['weight'] ?? null),
                'weight_unit' => $weightUnit,
            ];

            $days[$date] = $this->merge($days[$date] ?? null, $day);
        }

        // Round the summed totals and drop impossible values.
        foreach ($days as $date => $day) {
            foreach (['calories' => 20000, 'protein' => 2000, 'carbs' => 3000, 'fat' => 1500] as $field => $max) {
                $days[$date][$field] = $day[$field] === null || $day[$field] < 0 || $day[$field] > $max ? null : (int) round($day[$field]);
            }
            $days[$date]['weight'] = $day['weight'] !== null && $day['weight'] >= 20 && $day['weight'] <= 700 ? round($day['weight'], 1) : null;
        }

        return ['headers' => $headers, 'days' => $days];
    }

    /**
     * Rows for the same day add up (meals, foods); weight takes the latest value.
     *
     * @param  array<string, mixed>|null  $a
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    protected function merge(?array $a, array $b): array
    {
        if ($a === null) {
            return $b;
        }

        foreach (['calories', 'protein', 'carbs', 'fat'] as $field) {
            $a[$field] = $a[$field] === null && $b[$field] === null ? null : ($a[$field] ?? 0) + ($b[$field] ?? 0);
        }

        if ($b['weight'] !== null) {
            $a['weight'] = $b['weight'];
            $a['weight_unit'] = $b['weight_unit'];
        }

        return $a;
    }

    public function normalizeHeader(string $header): string
    {
        return Str::of($header)->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString();
    }

    public function detectSource(string $filename, array $headers): ?string
    {
        $name = Str::lower($filename);

        return match (true) {
            str_contains($name, 'macrofactor') || in_array('trend weight', $headers, true) || in_array('expenditure', $headers, true) => 'macrofactor',
            str_contains($name, 'nutrition summary') || str_contains($name, 'nutrition-summary') || str_contains($name, 'myfitnesspal') || (in_array('meal', $headers, true) && in_array('sodium mg', $headers, true)) => 'myfitnesspal',
            str_contains($name, 'loseit') || str_contains($name, 'lose it') || (in_array('quantity', $headers, true) && in_array('units', $headers, true)) => 'loseit',
            default => null,
        };
    }

    /**
     * @param  array<int, string|null>  $row
     */
    protected function number(array $row, ?int $index): ?float
    {
        if ($index === null) {
            return null;
        }

        $value = trim((string) ($row[$index] ?? ''));

        if ($value === '' || $value === '-') {
            return null;
        }

        // "1,234" (thousands) vs "64,2" (decimal comma).
        $value = preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $value) ? str_replace(',', '', $value) : str_replace(',', '.', $value);
        $value = preg_replace('/[^0-9.\-]/', '', $value) ?? '';

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Dates come as 2026-10-26, 10/26/2026 (US apps), 26/10/2026, 26.10.2026, "Oct 26, 2026",
     * sometimes with a time attached. Slash dates are read month-first unless that's impossible.
     */
    public function parseDate(string $value): ?string
    {
        $value = trim(preg_replace('/[T ]\d{1,2}:\d{2}.*$/', '', trim($value)) ?? '');

        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $value, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2}|\d{4})$/', $value, $m)) {
            [$mo, $d, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            if ($mo > 12 && $d <= 12) {
                [$mo, $d] = [$d, $mo];
            }
        } elseif (preg_match('/^(\d{1,2})[.\-](\d{1,2})[.\-](\d{4})$/', $value, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^[A-Za-z]{3,9}\.? \d{1,2},? \d{4}$|^\d{1,2} [A-Za-z]{3,9}\.? \d{4}$/', $value)) {
            try {
                $date = CarbonImmutable::parse($value);
                [$y, $mo, $d] = [$date->year, $date->month, $date->day];
            } catch (Throwable) {
                return null;
            }
        } else {
            return null;
        }

        if ($y < 100) {
            $y += 2000;
        }

        if (! checkdate($mo, $d, $y) || $y < 2000) {
            return null;
        }

        $date = CarbonImmutable::create($y, $mo, $d);

        return $date->isAfter(today()) ? null : $date->toDateString();
    }

    /**
     * @return array<string, string>
     */
    protected function csvsInZip(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException('That zip file couldn’t be opened.');
        }

        $csvs = [];

        for ($i = 0; $i < min($zip->numFiles, 50); $i++) {
            $name = (string) $zip->getNameIndex($i);
            $stat = $zip->statIndex($i);

            // Only CSVs at a sensible size; skip folders, macOS metadata and anything huge.
            if (! str_ends_with(Str::lower($name), '.csv') || str_starts_with(basename($name), '._') || ($stat['size'] ?? 0) > 20 * 1024 * 1024) {
                continue;
            }

            // MyFitnessPal also includes exercise files; only nutrition and measurement data matter here.
            if (preg_match('/exercise/i', basename($name))) {
                continue;
            }

            $csvs[basename($name)] = (string) $zip->getFromIndex($i);
        }

        $zip->close();

        return $csvs;
    }
}
