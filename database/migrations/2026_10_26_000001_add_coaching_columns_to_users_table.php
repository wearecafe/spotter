<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('coach')->after('email');
            $table->foreignId('coach_id')->nullable()->after('role')->constrained('users')->nullOnDelete();
            $table->string('goal')->nullable()->after('coach_id');
            $table->text('coach_notes')->nullable()->after('goal');
            $table->string('invitation_token', 64)->nullable()->unique()->after('coach_notes');
            $table->timestamp('invitation_accepted_at')->nullable()->after('invitation_token');
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coach_id');
            $table->dropColumn(['role', 'goal', 'coach_notes', 'invitation_token', 'invitation_accepted_at']);
        });
    }
};
