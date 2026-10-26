<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Coach business profile (set during onboarding) and per-user language.
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 5)->default('en')->after('role');
            $table->string('business_name')->nullable()->after('locale');
            $table->string('currency', 3)->default('CAD')->after('business_name');
            $table->string('coaching_languages', 10)->default('en')->after('currency'); // en, fr, both
            $table->string('client_count_band', 10)->nullable()->after('coaching_languages');
            $table->string('brand_color', 7)->default('#0A7A55')->after('client_count_band');
            $table->string('logo_path')->nullable()->after('brand_color');
            $table->string('subdomain', 40)->nullable()->unique()->after('logo_path');
            $table->string('import_source', 20)->nullable()->after('subdomain'); // truecoach, trainerize, sheets, fresh
            $table->unsignedTinyInteger('contract_months_left')->default(0)->after('import_source');
            $table->timestamp('onboarded_at')->nullable()->after('contract_months_left');
            $table->timestamp('invited_at')->nullable()->after('invitation_accepted_at');
        });

        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coach_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->date('starts_on');
            $table->unsignedTinyInteger('planned_weeks')->default(6);
            $table->timestamps();
        });

        Schema::table('workouts', function (Blueprint $table) {
            $table->foreignId('program_id')->nullable()->after('template_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('week')->nullable()->after('program_id');
            $table->unsignedTinyInteger('day')->nullable()->after('week');
            // Set the first time a completed workout is reported to the Coach Inbox.
            $table->timestamp('reported_at')->nullable()->after('client_feedback');
            $table->index(['program_id', 'week', 'day']);
        });

        Schema::table('workout_exercises', function (Blueprint $table) {
            $table->string('target_rpe', 10)->nullable()->after('load');
            // Progression rule applied when the next program week is generated.
            $table->string('progression', 20)->default('none')->after('notes'); // none, load, reps, hold
            $table->string('progression_step', 20)->nullable()->after('progression'); // e.g. "2.5 kg", "1"
            $table->json('changed_fields')->nullable()->after('progression_step'); // fields the last progression changed
            $table->string('change_reason')->nullable()->after('changed_fields');
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coach_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['coach_id', 'client_id', 'created_at']);
        });

        Schema::create('check_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->date('week_of');
            $table->decimal('weight', 5, 1)->nullable();
            $table->string('weight_unit', 2)->default('kg');
            $table->decimal('sleep_hours', 3, 1)->nullable();
            $table->unsignedInteger('avg_steps')->nullable();
            $table->unsignedTinyInteger('energy')->nullable(); // 1-5
            $table->unsignedTinyInteger('stress')->nullable(); // 1-5
            $table->text('notes')->nullable();
            $table->json('photos')->nullable(); // storage paths
            $table->timestamps();
            $table->unique(['client_id', 'week_of']);
        });

        Schema::create('form_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('workout_exercise_id')->constrained()->cascadeOnDelete();
            $table->string('video_path');
            $table->string('note')->nullable();
            $table->timestamps();
        });

        // The Coach Inbox: one ranked queue of things that need the coach.
        // Every AI output (summary, draft reply, suggestion) is a draft the coach approves.
        Schema::create('inbox_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coach_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 20); // check_in, form_check, missed, feedback, pr, message
            $table->nullableMorphs('subject');
            $table->unsignedSmallInteger('priority')->default(50);
            $table->string('headline');
            $table->text('summary')->nullable();
            $table->text('draft_reply')->nullable();
            $table->json('suggestion')->nullable();
            $table->string('drafted_by', 10)->nullable(); // ai, rules
            $table->string('status', 12)->default('open'); // open, snoozed, done
            $table->timestamp('snoozed_until')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['coach_id', 'status', 'priority']);
        });

        // Switching from another platform.
        Schema::create('imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coach_id')->constrained('users')->cascadeOnDelete();
            $table->string('source', 20);
            $table->string('status', 12)->default('review'); // review, finished
            $table->json('programs')->nullable(); // parsed programs keyed by client email
            $table->json('matches')->nullable(); // raw exercise name => {exercise_id, confidence, resolved}
            $table->json('stats')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imports');
        Schema::dropIfExists('inbox_items');
        Schema::dropIfExists('form_checks');
        Schema::dropIfExists('check_ins');
        Schema::dropIfExists('messages');

        Schema::table('workout_exercises', function (Blueprint $table) {
            $table->dropColumn(['target_rpe', 'progression', 'progression_step', 'changed_fields', 'change_reason']);
        });

        Schema::table('workouts', function (Blueprint $table) {
            $table->dropIndex(['program_id', 'week', 'day']);
            $table->dropConstrainedForeignId('program_id');
            $table->dropColumn(['week', 'day', 'reported_at']);
        });

        Schema::dropIfExists('programs');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['subdomain']);
            $table->dropColumn([
                'locale', 'business_name', 'currency', 'coaching_languages', 'client_count_band', 'brand_color',
                'logo_path', 'subdomain', 'import_source', 'contract_months_left', 'onboarded_at', 'invited_at',
            ]);
        });
    }
};
