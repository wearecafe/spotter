<?php

namespace Database\Seeders;

use App\Models\Exercise;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Loads the open free-exercise-db library (876 exercises, public domain) into the shared library.
 * See database/data/free-exercise-db.README.md. Safe to run repeatedly: rows are keyed by the
 * dataset's id, so re-running updates them instead of adding duplicates.
 */
class FreeExerciseDbSeeder extends Seeder
{
    public const SOURCE = 'free-exercise-db';

    public const DATA = 'data/free-exercise-db.json';

    /**
     * Spotter's own starter exercises and their equivalent in the dataset. The starter keeps its
     * name and gains the photos and instructions; the dataset copy isn't added separately.
     * Checked by hand: only true equivalents (e.g. the dataset's "Air Bike" is an ab exercise).
     */
    public const LINKS = [
        'Back Squat' => 'Barbell_Squat',
        'Front Squat' => 'Front_Barbell_Squat',
        'Goblet Squat' => 'Goblet_Squat',
        'Leg Press' => 'Leg_Press',
        'Leg Extension' => 'Leg_Extensions',
        'Conventional Deadlift' => 'Barbell_Deadlift',
        'Romanian Deadlift' => 'Romanian_Deadlift',
        'Lying Leg Curl' => 'Lying_Leg_Curls',
        'Hip Thrust' => 'Barbell_Hip_Thrust',
        'Glute Bridge' => 'Butt_Lift_Bridge',
        'Cable Kickback' => 'One-Legged_Cable_Kickback',
        'Standing Calf Raise' => 'Standing_Calf_Raises',
        'Bench Press' => 'Barbell_Bench_Press_-_Medium_Grip',
        'Incline Dumbbell Press' => 'Incline_Dumbbell_Press',
        'Push-up' => 'Pushups',
        'Cable Fly' => 'Cable_Crossover',
        'Pull-up' => 'Pullups',
        'Lat Pulldown' => 'Wide-Grip_Lat_Pulldown',
        'Barbell Row' => 'Bent_Over_Barbell_Row',
        'Single-Arm Dumbbell Row' => 'One-Arm_Dumbbell_Row',
        'Seated Cable Row' => 'Seated_Cable_Rows',
        'Overhead Press' => 'Standing_Military_Press',
        'Dumbbell Shoulder Press' => 'Dumbbell_Shoulder_Press',
        'Lateral Raise' => 'Side_Lateral_Raise',
        'Face Pull' => 'Face_Pull',
        'Barbell Curl' => 'Barbell_Curl',
        'Hammer Curl' => 'Hammer_Curls',
        'Triceps Pushdown' => 'Triceps_Pushdown',
        'Overhead Triceps Extension' => 'Standing_Dumbbell_Triceps_Extension',
        'Dip' => 'Dips_-_Triceps_Version',
        'Plank' => 'Plank',
        'Dead Bug' => 'Dead_Bug',
        'Pallof Press' => 'Pallof_Press',
        'Farmer Carry' => 'Farmers_Walk',
        'Rowing Machine' => 'Rowing_Stationary',
        'Treadmill Run' => 'Running_Treadmill',
        'World\'s Greatest Stretch' => 'Worlds_Greatest_Stretch',
    ];

    /** Dataset muscle names to Spotter's muscle groups. */
    public const MUSCLES = [
        'quadriceps' => 'Quads', 'hamstrings' => 'Hamstrings', 'glutes' => 'Glutes', 'calves' => 'Calves',
        'chest' => 'Chest', 'lats' => 'Back', 'middle back' => 'Back', 'lower back' => 'Back', 'traps' => 'Back',
        'shoulders' => 'Shoulders', 'biceps' => 'Biceps', 'triceps' => 'Triceps', 'forearms' => 'Forearms',
        'abdominals' => 'Core', 'adductors' => 'Hips', 'abductors' => 'Hips', 'neck' => 'Neck',
    ];

    public const EQUIPMENT = [
        'body only' => 'Bodyweight', 'barbell' => 'Barbell', 'dumbbell' => 'Dumbbell', 'cable' => 'Cable',
        'machine' => 'Machine', 'kettlebells' => 'Kettlebell', 'bands' => 'Band', 'medicine ball' => 'Medicine ball',
        'exercise ball' => 'Stability ball', 'foam roll' => 'Foam roller', 'e-z curl bar' => 'EZ bar', 'other' => 'Other',
    ];

    public function run(): void
    {
        $entries = collect(json_decode((string) file_get_contents(database_path(self::DATA)), true, flags: JSON_THROW_ON_ERROR))->keyBy('id');

        DB::transaction(function () use ($entries) {
            $this->enrichStarters($entries);
            $this->upsertLibrary($entries);
        });
    }

    /**
     * Give Spotter's starter exercises the dataset's photos and instructions.
     *
     * @param  Collection<string, array<string, mixed>>  $entries
     */
    protected function enrichStarters($entries): void
    {
        foreach (self::LINKS as $name => $id) {
            $entry = $entries->get($id);
            $exercise = Exercise::query()->whereNull('coach_id')->where('name', $name)->first();

            if (! $entry || ! $exercise) {
                continue;
            }

            $exercise->fill([
                'source' => 'spotter',
                'source_id' => $id,
                'category' => $entry['category'] ?? null,
                'level' => $entry['level'] ?? null,
                'secondary_muscles' => $this->muscleNames($entry['secondaryMuscles'] ?? []),
                'images' => $entry['images'] ?? [],
                'instructions' => $exercise->instructions ?: $this->instructions($entry),
            ])->save();
        }
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $entries
     */
    protected function upsertLibrary($entries): void
    {
        $linked = array_flip(self::LINKS);

        // Don't add a second exercise with the same name as one already in the shared library.
        $taken = Exercise::query()->whereNull('coach_id')->where(fn ($q) => $q->whereNull('source')->orWhere('source', '!=', self::SOURCE))
            ->pluck('name')->map(fn ($n) => Str::lower($n))->flip();

        $now = now();
        $rows = $entries
            ->reject(fn ($e) => isset($linked[$e['id']]) || $taken->has(Str::lower($e['name'])))
            ->map(fn ($e) => [
                'coach_id' => null,
                'source' => self::SOURCE,
                'source_id' => $e['id'],
                'name' => Str::limit($e['name'], 250, ''),
                'muscle_group' => $this->muscleGroup($e),
                'secondary_muscles' => json_encode($this->muscleNames($e['secondaryMuscles'] ?? [])),
                'equipment' => self::EQUIPMENT[$e['equipment'] ?? ''] ?? null,
                'category' => $e['category'] ?? null,
                'level' => $e['level'] ?? null,
                'instructions' => $this->instructions($e),
                'images' => json_encode($e['images'] ?? []),
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values();

        foreach ($rows->chunk(200) as $chunk) {
            Exercise::query()->upsert(
                $chunk->all(),
                ['source', 'source_id'],
                ['name', 'muscle_group', 'secondary_muscles', 'equipment', 'category', 'level', 'instructions', 'images', 'updated_at'],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    protected function muscleGroup(array $entry): ?string
    {
        if (($entry['category'] ?? null) === 'stretching') {
            return 'Mobility';
        }

        if (($entry['category'] ?? null) === 'cardio') {
            return 'Cardio';
        }

        $primary = $entry['primaryMuscles'] ?? [];

        return count($primary) > 2 ? 'Full body' : (self::MUSCLES[$primary[0] ?? ''] ?? null);
    }

    /**
     * @param  array<int, string>  $muscles
     * @return array<int, string>
     */
    protected function muscleNames(array $muscles): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($m) => self::MUSCLES[$m] ?? null, $muscles))));
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    protected function instructions(array $entry): ?string
    {
        $steps = array_values(array_filter(array_map('trim', $entry['instructions'] ?? [])));

        return $steps === [] ? null : implode("\n", $steps);
    }
}
