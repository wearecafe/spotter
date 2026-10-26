<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A team is the tenant: a solo coach's workspace or a gym with several trainers.
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('plan', 12)->default('starter'); // starter, coach, team
            $table->date('comp_until')->nullable(); // free months granted by Spotter staff
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspended_reason')->nullable();
            $table->text('staff_notes')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->after('coach_id')->constrained()->nullOnDelete();
            $table->string('team_role', 10)->nullable()->after('team_id'); // owner, admin, coach (coaches only)
            $table->boolean('is_staff')->default(false)->after('team_role');
            $table->timestamp('last_seen_at')->nullable()->after('remember_token');

            // Daily nutrition targets a coach sets for a client.
            $table->unsignedSmallInteger('calorie_target')->nullable()->after('coach_notes');
            $table->unsignedSmallInteger('protein_target')->nullable()->after('calorie_target');
            $table->unsignedSmallInteger('carbs_target')->nullable()->after('protein_target');
            $table->unsignedSmallInteger('fat_target')->nullable()->after('carbs_target');
        });

        Schema::create('team_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email');
            $table->string('role', 10)->default('coach'); // admin, coach
            $table->string('token', 64)->unique();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['team_id', 'email']);
        });

        // One row per client per day: weight, calories and macros.
        Schema::create('nutrition_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->date('logged_on');
            $table->decimal('weight', 5, 1)->nullable();
            $table->string('weight_unit', 2)->default('kg');
            $table->unsignedSmallInteger('calories')->nullable();
            $table->unsignedSmallInteger('protein')->nullable(); // grams
            $table->unsignedSmallInteger('carbs')->nullable();
            $table->unsignedSmallInteger('fat')->nullable();
            $table->string('source', 16)->default('manual'); // manual, macrofactor, myfitnesspal, loseit, csv
            $table->timestamps();
            $table->unique(['client_id', 'logged_on']);
        });

        // Everything Spotter staff do in the backoffice.
        Schema::create('staff_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 40);
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['team_id', 'created_at']);
        });

        $this->giveEveryCoachAWorkspace();
    }

    /**
     * Existing coaches become owners of their own workspace, and their clients join it.
     */
    protected function giveEveryCoachAWorkspace(): void
    {
        DB::table('users')->where('role', 'coach')->whereNull('team_id')->orderBy('id')->each(function ($coach) {
            $teamId = DB::table('teams')->insertGetId([
                'name' => $coach->business_name ?: $coach->name,
                'owner_id' => $coach->id,
                'plan' => 'starter',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('users')->where('id', $coach->id)->update(['team_id' => $teamId, 'team_role' => 'owner']);
            DB::table('users')->where('coach_id', $coach->id)->where('role', 'client')->update(['team_id' => $teamId]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_audit_logs');
        Schema::dropIfExists('nutrition_logs');
        Schema::dropIfExists('team_invitations');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('team_id');
            $table->dropColumn(['team_role', 'is_staff', 'last_seen_at', 'calorie_target', 'protein_target', 'carbs_target', 'fat_target']);
        });

        Schema::dropIfExists('teams');
    }
};
