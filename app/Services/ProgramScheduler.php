<?php

namespace App\Services;

use App\Models\Program;
use App\Models\ProgramDay;
use App\Models\ProgramExercise;
use App\Models\User;
use App\Models\Workout;
use App\Support\Load;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns a program blueprint into sessions on the client's calendar, every planned week.
 *
 * Sessions keep following the blueprint until the client starts them or the coach edits them
 * by hand. Loads and reps for future weeks are planned from the rules and re-planned from what
 * the client actually logged each time a session is completed.
 */
class ProgramScheduler
{
    /** Default weekdays (ISO, Monday = 1) for a given number of training days a week. */
    public const DEFAULT_WEEKDAYS = [
        1 => [1], 2 => [1, 4], 3 => [1, 3, 5], 4 => [1, 2, 4, 5], 5 => [1, 2, 3, 4, 5], 6 => [1, 2, 3, 4, 5, 6], 7 => [1, 2, 3, 4, 5, 6, 7],
    ];

    public function __construct(protected Progression $progression) {}

    /**
     * Start a program with empty days on sensible weekdays, scheduled for every week.
     */
    public function create(User $client, string $title, CarbonInterface|string $startsOn, int $weeks, int $daysPerWeek): Program
    {
        return DB::transaction(function () use ($client, $title, $startsOn, $weeks, $daysPerWeek) {
            $program = Program::create([
                'coach_id' => $client->coach_id,
                'client_id' => $client->id,
                'title' => $title,
                'starts_on' => $startsOn,
                'planned_weeks' => $weeks,
            ]);

            foreach (self::DEFAULT_WEEKDAYS[max(1, min($daysPerWeek, 7))] as $i => $weekday) {
                $program->days()->create(['position' => $i + 1, 'title' => __('Day :label', ['label' => chr(65 + $i)]), 'weekday' => $weekday]);
            }

            $this->sync($program);

            return $program;
        });
    }

    public function sync(Program $program): void
    {
        DB::transaction(function () use ($program) {
            foreach ($program->days()->get() as $day) {
                $this->syncDay($day->setRelation('program', $program));
            }
        });
    }

    /**
     * Bring every week's session for one day in line with the blueprint.
     */
    public function syncDay(ProgramDay $day): void
    {
        DB::transaction(function () use ($day) {
            $program = $day->program;
            $blueprint = $day->exercises()->get();
            $sessions = Workout::query()->where('program_day_id', $day->id)->with('items')->get()->keyBy('week');
            $plan = $this->plan($program, $day, $blueprint, $sessions);
            $skipped = $this->skippedWeeks($day);

            for ($week = 1; $week <= $program->planned_weeks; $week++) {
                $date = $program->dateFor($week, $day->weekday);
                $session = $sessions->get($week);

                // Before the program starts, or a week the coach skipped: no session.
                if ($date->lt($program->starts_on) || in_array($week, $skipped, true)) {
                    if ($session?->followsProgram()) {
                        $session->delete();
                    }

                    continue;
                }

                if ($session && ! $session->followsProgram()) {
                    continue;
                }

                $session ??= new Workout(['program_id' => $program->id, 'program_day_id' => $day->id, 'week' => $week]);
                $session->fill([
                    'coach_id' => $program->client?->coach_id ?? $program->coach_id,
                    'client_id' => $program->client_id,
                    'day' => $day->position,
                    'title' => $day->title,
                    'instructions' => $day->notes,
                    'scheduled_on' => $date,
                ])->save();

                $this->syncItems($session, $blueprint, $plan[$week] ?? []);
            }

            // Weeks dropped from the end of the program.
            $sessions->filter(fn (Workout $s) => $s->week > $program->planned_weeks && $s->followsProgram())->each->delete();
        });
    }

    /**
     * @param  Collection<int, ProgramExercise>  $blueprint
     * @param  array<int, array<string, mixed>>  $planned  keyed by program_exercise_id
     */
    protected function syncItems(Workout $session, Collection $blueprint, array $planned): void
    {
        $existing = $session->items()->get()->keyBy('program_exercise_id');

        foreach ($blueprint as $row) {
            $attributes = ($planned[$row->id] ?? $row->only(ProgramExercise::PRESCRIPTION_FIELDS)) + ['program_exercise_id' => $row->id];

            if ($item = $existing->get($row->id)) {
                $item->fill($attributes)->save();
            } else {
                $session->items()->create($attributes);
            }
        }

        // Exercises removed from the blueprint (and anything not from it) leave the session.
        $session->items()->where(fn ($q) => $q->whereNull('program_exercise_id')->orWhereNotIn('program_exercise_id', $blueprint->pluck('id')))->delete();
    }

    /**
     * Plan every week's load and reps for each blueprint exercise.
     *
     * Each week looks at the day's previous session (skipped weeks and weeks before the start
     * don't count). Completed: the rule is checked against what the client logged, progress or
     * hold. Missed: hold. Not happened yet: plan the next step assuming it goes to plan. A
     * completed session the coach lightened by hand (a deload) doesn't reset the plan.
     *
     * @param  Collection<int, ProgramExercise>  $blueprint
     * @param  Collection<int, Workout>  $sessions  keyed by week
     * @return array<int, array<int, array<string, mixed>>> week => program_exercise_id => attributes
     */
    public function plan(Program $program, ProgramDay $day, Collection $blueprint, Collection $sessions): array
    {
        $plan = [];
        $weeks = $this->scheduledWeeks($program, $day, $sessions);

        foreach ($blueprint as $row) {
            $planned = []; // week => [load, reps] as planned
            $previousWeek = null;

            for ($week = 1; $week <= $program->planned_weeks; $week++) {
                $changed = [];
                $reason = null;
                [$load, $reps] = [$row->load, $row->reps];

                if ($week > $row->base_week && $previousWeek !== null && $previousWeek >= $row->base_week) {
                    [$load, $reps] = $planned[$previousWeek];
                    $previous = $sessions->get($previousWeek);
                    $previousItem = $previous?->items->firstWhere('program_exercise_id', $row->id);

                    if ($previous && $previous->isCompleted() && $previousItem) {
                        $deload = $previous->customized_at !== null
                            && ($done = Load::toKg($previousItem->load)) !== null
                            && ($plannedKg = Load::toKg($load)) !== null
                            && $done < $plannedKg - 0.01;

                        if ($deload) {
                            $reason = __('After a lighter week :n, picks up where the plan left off', ['n' => $previousWeek]);
                        } else {
                            // Judge that week against what it actually prescribed, with today's rule.
                            $previousItem->progression = $row->progression;
                            $previousItem->progression_step = $row->progression_step;
                            $next = $this->progression->nextPrescription($previousItem);
                            [$changed, $reason, $load, $reps] = [$next['changed_fields'], $next['change_reason'], $next['load'], $next['reps']];
                        }
                    } elseif ($previous && ! $previous->isCompleted() && $previous->scheduled_on?->lt(today())) {
                        $reason = in_array($row->progression, ['load', 'reps'], true) ? __('Held: week :n wasn’t completed', ['n' => $previousWeek]) : null;
                    } else {
                        $step = $this->progression->advance($load, $reps, $row->progression, $row->progression_step);
                        [$load, $reps, $changed] = [$step['load'], $step['reps'], $step['changed']];
                        $reason = $changed ? __('Planned: progresses if week :n goes to plan', ['n' => $previousWeek]) : null;
                    }
                }

                // Rep ranges stop at the top of the coach's range ("8-10" climbs to 10, then holds).
                $capped = $this->capReps($reps, $row->reps);
                if ($capped !== $reps) {
                    $reps = $capped;
                    $changed = array_values(array_diff($changed, ['reps']));
                    $reason = $row->progression === 'reps' ? __('Top of the rep range: consider adding load') : $reason;
                }

                $planned[$week] = [$load, $reps];

                if (in_array($week, $weeks, true)) {
                    $previousWeek = $week;
                }

                $plan[$week][$row->id] = array_merge($row->only(ProgramExercise::PRESCRIPTION_FIELDS), [
                    'load' => $load,
                    'reps' => $reps,
                    'changed_fields' => $changed,
                    'change_reason' => $reason,
                ]);
            }
        }

        return $plan;
    }

    /**
     * Weeks that have a session for this day: on or after the start, not skipped, or already on the calendar.
     *
     * @param  Collection<int, Workout>  $sessions
     * @return array<int, int>
     */
    protected function scheduledWeeks(Program $program, ProgramDay $day, Collection $sessions): array
    {
        $skipped = $this->skippedWeeks($day);
        $weeks = [];

        for ($week = 1; $week <= $program->planned_weeks; $week++) {
            $existing = $sessions->get($week);
            $scheduled = ! $program->dateFor($week, $day->weekday)->lt($program->starts_on) && ! in_array($week, $skipped, true);

            if (($existing && ! $existing->followsProgram()) || $scheduled) {
                $weeks[] = $week;
            }
        }

        return $weeks;
    }

    protected function capReps(?string $reps, ?string $blueprint): ?string
    {
        if ($reps === null || ! preg_match('/^\s*\d+\s*-\s*(\d+)\s*$/', (string) $blueprint, $range)) {
            return $reps;
        }

        return preg_match('/^\s*(\d+)\s*$/', $reps, $n) && (int) $n[1] > (int) $range[1] ? $range[1] : $reps;
    }

    /**
     * Where a new starting load or rep target should apply from: the earliest upcoming session
     * that still follows the program. Earlier weeks (done, missed or edited) keep what they had.
     */
    public function firstOpenWeek(ProgramDay $day): int
    {
        $sessions = Workout::query()->where('program_day_id', $day->id)->with('items')->orderBy('week')->get();

        $open = $sessions->first(fn (Workout $s) => $s->followsProgram() && $s->scheduled_on && ! $s->scheduled_on->lt(today()));

        if ($open) {
            return $open->week;
        }

        // Nothing open from today on yet: apply from the week after the last one on the calendar.
        return min(((int) $sessions->max('week')) + 1, 255);
    }

    /**
     * Move the program's start date. Sessions the client started or the coach edited stay on their
     * dates (renumbered into the new weeks); everything still following the program is rebuilt.
     *
     * @return string|null why it can't move, or null when done
     */
    public function moveStart(Program $program, CarbonInterface|string $startsOn): ?string
    {
        $startsOn = Carbon::parse($startsOn)->startOfDay();
        $sessions = Workout::query()->where('program_id', $program->id)->with('items')->get();
        $locked = $sessions->reject(fn (Workout $s) => $s->followsProgram());

        if ($locked->contains(fn (Workout $s) => $s->scheduled_on && $s->scheduled_on->lt($startsOn->copy()->startOfWeek()))) {
            return __('Some sessions before that date were already started or edited. Pick a start date on or before :date.', [
                'date' => $locked->min('scheduled_on')->translatedFormat('j M Y'),
            ]);
        }

        DB::transaction(function () use ($program, $startsOn, $sessions, $locked) {
            $program->update(['starts_on' => $startsOn]);

            $sessions->filter(fn (Workout $s) => $s->followsProgram())->each->delete();

            foreach ($locked as $session) {
                if ($session->scheduled_on) {
                    $session->forceFill(['week' => $program->weekOf($session->scheduled_on)])->save();
                }
            }

            $lastLocked = (int) $locked->max('week');
            if ($lastLocked > $program->planned_weeks) {
                $program->update(['planned_weeks' => min($lastLocked, 52)]);
            }

            $this->sync($program->fresh());
        });

        return null;
    }

    /**
     * Skip one week of a day (the coach removed that session). Sync won't recreate it.
     */
    public function skip(Workout $session): void
    {
        $day = $session->programDay;

        if ($day) {
            $day->update(['skipped_weeks' => collect($this->skippedWeeks($day))->push($session->week)->unique()->sort()->values()->all()]);
        }

        $session->delete();

        // Later weeks are re-planned: a skipped week doesn't count as a step.
        if ($day) {
            $this->syncDay($day->fresh());
        }
    }

    public function unskip(ProgramDay $day, int $week): void
    {
        $weeks = array_values(array_diff($this->skippedWeeks($day), [$week]));
        $day->update(['skipped_weeks' => $weeks ?: null]);

        $this->syncDay($day);
    }

    /**
     * @return array<int, int>
     */
    public function skippedWeeks(ProgramDay $day): array
    {
        return array_map('intval', $day->skipped_weeks ?? []);
    }

    /**
     * Mark a session as edited by hand so program edits stop changing it.
     */
    public function customize(Workout $session): void
    {
        if ($session->program_day_id && $session->customized_at === null) {
            $session->forceFill(['customized_at' => now()])->save();
        }
    }

    /**
     * Put a hand-edited session back on the program.
     */
    public function reset(Workout $session): void
    {
        // Once the client has logged anything, the session is theirs: resetting would lose their work.
        if (! $session->program_day_id || $session->load('items')->isStarted()) {
            return;
        }

        $session->forceFill(['customized_at' => null])->save();
        $session->items()->whereNull('program_exercise_id')->delete();

        $this->syncDay($session->programDay);
    }

    /**
     * Re-plan after the client finishes (or reopens) a session.
     */
    public function sessionChanged(Workout $session): void
    {
        if ($session->programDay) {
            $this->syncDay($session->programDay);
        }
    }
}
