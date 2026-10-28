<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Trainer-run sessions: in-person clients who don't use the app, and
     * sessions a trainer logged on a client's behalf.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('in_person')->default(false)->after('goal');
        });

        Schema::table('workouts', function (Blueprint $table) {
            $table->foreignId('logged_by_id')->nullable()->after('completed_at')->constrained('users')->nullOnDelete();
            $table->text('session_notes')->nullable()->after('client_feedback');
        });
    }

    public function down(): void
    {
        Schema::table('workouts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('logged_by_id');
            $table->dropColumn('session_notes');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('in_person');
        });
    }
};
