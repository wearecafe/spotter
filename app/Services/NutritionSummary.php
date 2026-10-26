<?php

namespace App\Services;

use App\Models\NutritionLog;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Averages and target adherence over recent days, for the coach view and inbox drafts.
 */
class NutritionSummary
{
    public const FIELDS = ['calories', 'protein', 'carbs', 'fat'];

    /**
     * @return array{logged_days: int, days: int, averages: array<string, int|null>, targets: array<string, int|null>, on_target_days: int|null, weight_change: float|null, weight_unit: string|null}
     */
    public function forDays(User $client, int $days = 7): array
    {
        /** @var Collection<int, NutritionLog> $logs */
        $logs = $client->nutritionLogs()
            ->whereBetween('logged_on', [today()->subDays($days - 1)->toDateString(), today()->toDateString()])
            ->orderBy('logged_on')
            ->get();

        $averages = [];
        foreach (self::FIELDS as $field) {
            $values = $logs->pluck($field)->filter(fn ($v) => $v !== null);
            $averages[$field] = $values->isEmpty() ? null : (int) round($values->avg());
        }

        $targets = [
            'calories' => $client->calorie_target,
            'protein' => $client->protein_target,
            'carbs' => $client->carbs_target,
            'fat' => $client->fat_target,
        ];

        $weights = $logs->filter(fn ($l) => $l->weight !== null)->values();

        return [
            'logged_days' => $logs->filter(fn ($l) => $l->calories !== null)->count(),
            'days' => $days,
            'averages' => $averages,
            'targets' => $targets,
            // A day is on target when calories land within 10% of the target.
            'on_target_days' => $client->calorie_target
                ? $logs->filter(fn ($l) => $l->calories !== null && abs($l->calories - $client->calorie_target) <= $client->calorie_target * 0.1)->count()
                : null,
            'weight_change' => $weights->count() >= 2 ? round((float) $weights->last()->weight - (float) $weights->first()->weight, 1) : null,
            'weight_unit' => $weights->last()?->weight_unit,
        ];
    }

    /**
     * One plain line for summaries, e.g. "Nutrition (6 of 7 days logged): 1,850 kcal (target 2,000), 112 g protein (target 130)."
     */
    public function sentence(User $client, int $days = 7): ?string
    {
        $s = $this->forDays($client, $days);

        if ($s['logged_days'] === 0) {
            return null;
        }

        $part = fn (string $field, string $unit) => $s['averages'][$field] === null ? null
            : number_format($s['averages'][$field]).' '.$unit.($s['targets'][$field] ? ' ('.__('target :n', ['n' => number_format($s['targets'][$field])]).')' : '');

        $parts = array_filter([$part('calories', 'kcal'), $part('protein', __('g protein'))]);

        return __('Nutrition, :logged of :days days logged: :parts.', ['logged' => $s['logged_days'], 'days' => $days, 'parts' => implode(', ', $parts)]);
    }
}
