<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Clients: may move their own sessions to another day in the same week.
            $table->boolean('can_reschedule')->default(true)->after('in_person');
        });

        Schema::table('workouts', function (Blueprint $table) {
            // Set when the client moved the session; program syncs keep it on the day they chose.
            $table->date('moved_from')->nullable()->after('scheduled_on');
        });
    }

    public function down(): void
    {
        Schema::table('workouts', function (Blueprint $table) {
            $table->dropColumn('moved_from');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('can_reschedule');
        });
    }
};
