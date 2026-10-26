<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exercises', function (Blueprint $table) {
            // Where a shared exercise came from: spotter (our own list) or free-exercise-db.
            $table->string('source', 24)->nullable()->after('coach_id');
            $table->string('source_id', 120)->nullable()->after('source');
            $table->string('category', 30)->nullable()->after('equipment'); // strength, stretching, plyometrics…
            $table->string('level', 15)->nullable()->after('category'); // beginner, intermediate, expert
            $table->json('secondary_muscles')->nullable()->after('muscle_group');
            $table->json('images')->nullable()->after('video_url'); // paths relative to EXERCISE_IMAGE_BASE_URL
            $table->unique(['source', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::table('exercises', function (Blueprint $table) {
            $table->dropUnique(['source', 'source_id']);
            $table->dropColumn(['source', 'source_id', 'category', 'level', 'secondary_muscles', 'images']);
        });
    }
};
