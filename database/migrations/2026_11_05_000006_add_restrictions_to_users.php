<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Clients: injury restrictions their coach recorded, e.g. [{"key": "knee_flexion", "side": "left", "note": "..."}].
            $table->json('restrictions')->nullable()->after('coach_notes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('restrictions');
        });
    }
};
