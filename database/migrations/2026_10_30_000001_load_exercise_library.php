<?php

use Database\Seeders\ExerciseLibrarySeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Loads the shared exercise library (Spotter's starters plus free-exercise-db) as part of
 * `migrate`, so it reaches every environment without a separate deploy step. The seeder is
 * idempotent, so environments that already ran it are unaffected. Tests load it on demand.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        app(ExerciseLibrarySeeder::class)->setContainer(app())->__invoke();
    }

    public function down(): void
    {
        // Library rows may be referenced by programs and logs; leave them in place.
    }
};
