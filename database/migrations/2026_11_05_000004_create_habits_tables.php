<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Habits: daily or weekly targets beyond the workouts, such as steps, a walk or water.
 * Coaches set them for a client, and clients can add their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('habits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 80);
            $table->string('type', 10)->default('check'); // check, number
            $table->decimal('target', 10, 2)->nullable(); // number habits: e.g. 8000 (steps), 2 (litres)
            $table->string('unit', 20)->nullable();
            $table->unsignedTinyInteger('days_per_week')->default(7); // 7 = daily
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamp('ended_at')->nullable(); // stopped; history is kept
            $table->timestamps();

            $table->index(['client_id', 'ended_at']);
        });

        Schema::create('habit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('habit_id')->constrained()->cascadeOnDelete();
            $table->date('logged_on');
            $table->decimal('value', 10, 2)->nullable(); // number habits
            $table->boolean('done')->default(false); // checked, or value at or over target
            $table->timestamps();

            $table->unique(['habit_id', 'logged_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('habit_logs');
        Schema::dropIfExists('habits');
    }
};
