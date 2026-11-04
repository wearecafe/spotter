<?php

namespace App\Support;

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Injury restrictions a coach records for a client ("knee: no deep knee bends"), and which
 * exercises run into them.
 *
 * Matching reads the exercise's name (plus its category and muscle group), so it catches the
 * common lifts in the library and in coaches' own exercises, but it's a prompt for the coach's
 * judgement, not a medical screen: it can miss an exercise or flag one that's fine.
 */
class Restrictions
{
    /**
     * key => area, label, and what conflicts: name patterns, minus exceptions, plus whole categories.
     *
     * @var array<string, array{area: string, label: string, match: string, except?: string, categories?: list<string>}>
     */
    public const CATALOG = [
        'knee_flexion' => [
            'area' => 'Knee',
            'label' => 'No deep knee bends',
            'match' => '/squat|lunge|step[- ]?up|pistol|leg press|hack|sissy|wall sit|cossack|split/i',
            'except' => '/jump|split jerk/i',
        ],
        'impact' => [
            'area' => 'Knee / ankle',
            'label' => 'No jumping or impact',
            'match' => '/jump|hop\b|hops\b|bound|plyo|burpee|sprint|\brun|jog|skip|depth drop|jumping jack|split jerk/i',
            'categories' => ['plyometrics'],
        ],
        'spinal_loading' => [
            'area' => 'Lower back',
            'label' => 'No heavy loading on the spine',
            'match' => '/deadlift|good ?morning|bent[- ]over|t-bar row|barbell row|pendlay|clean|snatch|jerk|rack pull|zercher|back squat|barbell squat|front squat|military press|push press|standing (barbell|military|overhead)/i',
        ],
        'spinal_flexion' => [
            'area' => 'Lower back',
            'label' => 'No loaded bending (sit-ups, crunches)',
            'match' => '/sit[- ]?up|crunch|toe touch|jefferson|v-up|jackknife|russian twist|toes to bar|good ?morning/i',
        ],
        'overhead' => [
            'area' => 'Shoulder',
            'label' => 'Nothing overhead',
            'match' => '/overhead|military|shoulder press|push press|arnold|snatch|jerk|handstand|pull[- ]?up|chin[- ]?up|lat pull|upright row|pike push|wall walk/i',
        ],
        'pressing' => [
            'area' => 'Shoulder / chest',
            'label' => 'No heavy pressing',
            'match' => '/bench press|chest press|push[- ]?up|press[- ]?up|\bdips?\b|floor press|fly\b|flye|pec deck/i',
        ],
        'elbow' => [
            'area' => 'Elbow',
            'label' => 'No loaded elbow bending or straightening',
            'match' => '/curl|skull ?crusher|tricep|pushdown|kickback|\bdips?\b|close[- ]grip|french press/i',
            'except' => '/leg curl|hamstring curl|nordic|lying leg|seated leg|wrist curl/i',
        ],
        'wrist' => [
            'area' => 'Wrist',
            'label' => 'No weight on the hands or wrists',
            'match' => '/push[- ]?up|press[- ]?up|plank|handstand|front squat|clean|burpee|mountain climber|crawl|wrist curl|\bdips?\b/i',
        ],
        'hip_flexion' => [
            'area' => 'Hip',
            'label' => 'No deep hip bends',
            'match' => '/squat|lunge|deadlift|good ?morning|leg raise|knee raise|hip flexor|pigeon|split/i',
        ],
        'neck' => [
            'area' => 'Neck',
            'label' => 'No load on the neck',
            'match' => '/neck|shrug|headstand|back squat|wrestler|bridge/i',
            'except' => '/glute bridge|hip bridge/i',
        ],
        'calf' => [
            'area' => 'Ankle / calf',
            'label' => 'No calf loading',
            'match' => '/calf|calves|heel raise|toe raise|skip|jump rope/i',
        ],
    ];

    public const SIDES = ['left' => 'Left', 'right' => 'Right', 'both' => 'Both'];

    /**
     * The client's restrictions, with their catalog entries.
     *
     * @return Collection<int, array{key: string, area: string, label: string, side: string|null, note: string|null}>
     */
    public static function forClient(?User $client): Collection
    {
        return collect($client?->restrictions ?? [])
            ->filter(fn ($r) => is_array($r) && isset(self::CATALOG[$r['key'] ?? '']))
            ->map(fn (array $r) => [
                'key' => $r['key'],
                'area' => self::CATALOG[$r['key']]['area'],
                'label' => self::CATALOG[$r['key']]['label'],
                'side' => $r['side'] ?? null,
                'note' => $r['note'] ?? null,
            ])
            ->values();
    }

    /**
     * "Knee (left): No deep knee bends".
     *
     * @param  array{area: string, label: string, side: string|null}  $restriction
     */
    public static function describe(array $restriction): string
    {
        $area = __($restriction['area']);

        if ($restriction['side'] && $restriction['side'] !== 'both') {
            $area .= ' ('.mb_strtolower(__(self::SIDES[$restriction['side']])).')';
        }

        return $area.': '.__($restriction['label']);
    }

    public static function conflicts(Exercise $exercise, string $key): bool
    {
        $rule = self::CATALOG[$key] ?? null;

        if ($rule === null) {
            return false;
        }

        if (in_array($exercise->category, $rule['categories'] ?? [], true)) {
            return true;
        }

        return (bool) preg_match($rule['match'], $exercise->name)
            && ! (isset($rule['except']) && preg_match($rule['except'], $exercise->name));
    }

    /**
     * Which of the client's restrictions an exercise runs into.
     *
     * @param  Collection<int, array<string, mixed>>  $restrictions  from forClient()
     * @return Collection<int, array<string, mixed>>
     */
    public static function conflictsFor(Exercise $exercise, Collection $restrictions): Collection
    {
        return $restrictions->filter(fn (array $r) => self::conflicts($exercise, $r['key']))->values();
    }
}
