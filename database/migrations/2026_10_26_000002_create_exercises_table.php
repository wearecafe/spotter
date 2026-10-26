<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exercises', function (Blueprint $table) {
            $table->id();
            // Null coach_id = shared Spotter library exercise available to every coach.
            $table->foreignId('coach_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('muscle_group')->nullable();
            $table->string('equipment')->nullable();
            $table->text('instructions')->nullable();
            $table->string('video_url')->nullable();
            $table->timestamps();

            $table->index(['coach_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercises');
    }
};
