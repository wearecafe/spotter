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

        $this->convertExistingPrograms();
    }

    /**
     * Build a blueprint from each existing program's first week. Sessions already on the calendar
     * after week 1 are frozen as hand-edited, so nothing a coach planned changes underneath them.
     */
    protected function convertExistingPrograms(): void
    {
        DB::table('programs')->orderBy('id')->lazyById(100)->each(function ($program) {
            $workouts = DB::table('workouts')->where('program_id', $program->id)->orderBy('week')->orderBy('day')->get();

            foreach ($workouts->groupBy('day') as $dayNumber => $sessions) {
                $first = $sessions->first();
                $now = now();

                $dayId = DB::table('program_days')->insertGetId([
                    'program_id' => $program->id,
                    'position' => (int) $dayNumber ?: 1,
                    'title' => $first->title,
                    'weekday' => $first->scheduled_on ? Carbon::parse($first->scheduled_on)->dayOfWeekIso : 1,
                    'notes' => $first->instructions,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $blueprintIds = [];
                foreach (DB::table('workout_exercises')->where('workout_id', $first->id)->orderBy('position')->orderBy('id')->get() as $item) {
                    $blueprintIds[$item->exercise_id] ??= DB::table('program_exercises')->insertGetId([
                        'program_day_id' => $dayId,
                        'exercise_id' => $item->exercise_id,
                        'position' => $item->position,
                        'sets' => $item->sets,
                        'reps' => $item->reps,
                        'load' => $item->load,
                        'target_rpe' => $item->target_rpe,
                        'rest_seconds' => $item->rest_seconds,
                        'notes' => $item->notes,
                        'progression' => $item->progression,
                        'progression_step' => $item->progression_step,
                        'base_week' => (int) ($first->week ?: 1),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                foreach ($sessions as $session) {
                    DB::table('workouts')->where('id', $session->id)->update([
                        'program_day_id' => $dayId,
                        'customized_at' => $session->id !== $first->id && $session->completed_at === null ? $now : null,
                    ]);

                    foreach ($blueprintIds as $exerciseId => $blueprintId) {
                        DB::table('workout_exercises')->where('workout_id', $session->id)->where('exercise_id', $exerciseId)->update(['program_exercise_id' => $blueprintId]);
                    }
                }
            }
        });
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
