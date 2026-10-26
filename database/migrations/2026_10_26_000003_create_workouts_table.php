<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coach_id')->constrained('users')->cascadeOnDelete();
            // Null client_id = reusable template in the coach's library.
            $table->foreignId('client_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('workouts')->nullOnDelete();
            $table->string('title');
            $table->text('instructions')->nullable();
            $table->date('scheduled_on')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('client_feedback')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'scheduled_on']);
            $table->index(['coach_id', 'client_id']);
        });

        Schema::create('workout_exercises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workout_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exercise_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->unsignedTinyInteger('sets')->default(3);
            $table->string('reps')->nullable();
            $table->string('load')->nullable();
            $table->unsignedSmallInteger('rest_seconds')->nullable();
            $table->text('notes')->nullable();
            // Client results: [{"reps": "10", "load": "135 lb", "done": true}, ...]
            $table->json('logged_sets')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workout_exercises');
        Schema::dropIfExists('workouts');
    }
};
