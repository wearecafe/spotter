<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archived clients keep every record but drop out of the coach's day-to-day work and,
 * from the next billing month, out of the client count.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Clients.
            $table->timestamp('archived_at')->nullable()->after('in_person');
            $table->string('archived_reason', 10)->nullable()->after('archived_at'); // coach, inactive
            $table->string('archived_access', 10)->nullable()->after('archived_reason'); // read_only, locked

            // Coaches: archive their own clients after this many days without activity (null = off),
            // and what those clients can still do once archived.
            $table->unsignedSmallInteger('auto_archive_days')->nullable()->after('fat_target');
            $table->string('archived_client_access', 10)->default('read_only')->after('auto_archive_days');

            $table->index(['coach_id', 'archived_at']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['coach_id', 'archived_at']);
            $table->dropColumn(['archived_at', 'archived_reason', 'archived_access', 'auto_archive_days', 'archived_client_access']);
        });
    }
};
