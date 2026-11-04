<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workout_exercises', function (Blueprint $table) {
            // The client's own note on an exercise ("eased off, knee was sore"), and whether it hurt.
            $table->text('client_note')->nullable()->after('logged_sets');
            $table->boolean('pain')->default(false)->after('client_note');
        });
    }

    public function down(): void
    {
        Schema::table('workout_exercises', function (Blueprint $table) {
            $table->dropColumn(['client_note', 'pain']);
        });
    }
};
