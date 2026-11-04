<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Coaches: which progress number exercise history shows, to them and their clients.
            $table->string('history_metric', 10)->default('heaviest')->after('archived_client_access'); // heaviest, e1rm, volume
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('history_metric');
        });
    }
};
