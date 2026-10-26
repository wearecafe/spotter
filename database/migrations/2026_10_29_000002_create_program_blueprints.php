<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Programs become a blueprint (days on fixed weekdays, each with exercises and rules) that
 * Spotter schedules onto the client's calendar for every planned week. Calendar sessions link
 * back to the blueprint and follow its edits until they're started or edited by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('program_days')) {
            Schema::create('program_days', function (Blueprint $table) {
                $table->id();
                $table->foreignId('program_id')->constrained()->cascadeOnDelete();
                $table->unsignedTinyInteger('position')->default(1);
                $table->string('title');
                $table->unsignedTinyInteger('weekday')->default(1); // ISO: 1 = Monday … 7 = Sunday
                $table->text('notes')->nullable();
                $table->json('skipped_weeks')->nullable(); // weeks the coach removed this day's session
                $table->timestamps();
            });

            Schema::create('program_exercises', function (Blueprint $table) {
                $table->id();
                $table->foreignId('program_day_id')->constrained()->cascadeOnDelete();
                $table->foreignId('exercise_id')->constrained()->restrictOnDelete();
                $table->unsignedInteger('position')->default(0);
                $table->unsignedTinyInteger('sets')->default(3);
                $table->string('reps')->nullable();
                $table->string('load')->nullable();
                $table->string('target_rpe', 10)->nullable();
                $table->unsignedSmallInteger('rest_seconds')->nullable();
                $table->text('notes')->nullable();
                $table->string('progression', 20)->default('none');
                $table->string('progression_step', 20)->nullable();
                // The week these values apply from; earlier weeks keep whatever they were planned with.
                $table->unsignedTinyInteger('base_week')->default(1);
                $table->timestamps();
            });

            Schema::table('workouts', function (Blueprint $table) {
                $table->foreignId('program_day_id')->nullable()->after('program_id')->constrained()->nullOnDelete();
                // Set when a coach edits a single session by hand; program edits then leave it alone.
                $table->timestamp('customized_at')->nullable()->after('reported_at');
            });

            Schema::table('workout_exercises', function (Blueprint $table) {
                $table->foreignId('program_exercise_id')->nullable()->after('exercise_id')->constrained()->nullOnDelete();
            });
        } // Schema already created by an earlier, interrupted run: just finish the conversion.

        $this->convertExistingPrograms();
    }

    /**
     * Build a blueprint from each existing program's first sessions. Sessions are renumbered by the
     * calendar week they actually fall in (old programs could start midweek and spill days into the
     * next week). Sessions already on the calendar after each day's first are frozen as hand-edited,
     * so nothing a coach planned changes underneath them. Each program converts in its own
     * transaction and converted programs are skipped, so a failed run can simply be re-run.
     */
    protected function convertExistingPrograms(): void
    {
        DB::table('programs')->orderBy('id')->lazyById(100)->each(function ($program) {
            if (DB::table('program_days')->where('program_id', $program->id)->exists()) {
                return;
            }

            DB::transaction(fn () => $this->convertProgram($program));
        });
    }

    protected function convertProgram(object $program): void
    {
        $now = now();
        $weekOne = Carbon::parse($program->starts_on)->startOfWeek();
        $workouts = DB::table('workouts')->where('program_id', $program->id)->orderBy('scheduled_on')->orderBy('id')->get();
        $lastWeek = (int) $program->planned_weeks;

        // Calendar week each session really falls in.
        foreach ($workouts as $workout) {
            $workout->week = $workout->scheduled_on
                ? max(1, intdiv((int) $weekOne->diffInDays(Carbon::parse($workout->scheduled_on)->startOfDay(), false), 7) + 1)
                : (int) ($workout->week ?: 1);
            $lastWeek = max($lastWeek, $workout->week);
        }

        foreach ($workouts->groupBy(fn ($w) => (int) ($w->day ?: 1)) as $dayNumber => $sessions) {
            $sessions = $sessions->sortBy('week')->values();
            $first = $sessions->first();

            $dayId = DB::table('program_days')->insertGetId([
                'program_id' => $program->id,
                'position' => $dayNumber,
                'title' => $first->title,
                'weekday' => $first->scheduled_on ? Carbon::parse($first->scheduled_on)->dayOfWeekIso : 1,
                'notes' => $first->instructions,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // One blueprint row per exercise entry (a day can list the same exercise twice, e.g. a back-off set).
            $blueprint = []; // exercise_id => [blueprint id, …] in order
            foreach (DB::table('workout_exercises')->where('workout_id', $first->id)->orderBy('position')->orderBy('id')->get() as $item) {
                $blueprint[$item->exercise_id][] = DB::table('program_exercises')->insertGetId([
                    'program_day_id' => $dayId,
                    'exercise_id' => $item->exercise_id,
                    'position' => $item->position,
                    'sets' => $item->sets,
                    'reps' => $item->reps,
                    'load' => $item->load,
                    'target_rpe' => $item->target_rpe,
                    'rest_seconds' => $item->rest_seconds,
                    'notes' => $item->notes,
                    'progression' => $item->progression ?? 'none',
                    'progression_step' => $item->progression_step,
                    'base_week' => $first->week,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $seenWeeks = [];
            foreach ($sessions as $session) {
                // Two old sessions in the same calendar week for one day: keep the second as a one-off.
                $duplicate = isset($seenWeeks[$session->week]);
                $seenWeeks[$session->week] = true;

                DB::table('workouts')->where('id', $session->id)->update([
                    'week' => $session->week,
                    'program_day_id' => $duplicate ? null : $dayId,
                    'customized_at' => $session->id !== $first->id && $session->completed_at === null ? $now : null,
                ]);

                // Link each item to the blueprint row in the same position among its exercise's entries.
                $nth = [];
                foreach (DB::table('workout_exercises')->where('workout_id', $session->id)->orderBy('position')->orderBy('id')->get() as $item) {
                    $index = $nth[$item->exercise_id] = ($nth[$item->exercise_id] ?? -1) + 1;

                    if ($blueprintId = $blueprint[$item->exercise_id][$index] ?? null) {
                        DB::table('workout_exercises')->where('id', $item->id)->update(['program_exercise_id' => $blueprintId]);
                    }
                }
            }
        }

        DB::table('programs')->where('id', $program->id)->update(['planned_weeks' => min($lastWeek, 52)]);
    }

    public function down(): void
    {
        Schema::table('workout_exercises', function (Blueprint $table) {
            $table->dropConstrainedForeignId('program_exercise_id');
        });

        Schema::table('workouts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('program_day_id');
            $table->dropColumn('customized_at');
        });

        Schema::dropIfExists('program_exercises');
        Schema::dropIfExists('program_days');
    }
};
