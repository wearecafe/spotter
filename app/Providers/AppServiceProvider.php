<?php

namespace App\Providers;

use App\Models\Import;
use App\Models\InboxItem;
use App\Models\Program;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // A coach manages their own clients; team owners and admins manage the whole team's.
        Gate::define('manage-client', fn (User $coach, User $client): bool => $coach->managesClient($client));

        Gate::define('manage-inbox-item', fn (User $coach, InboxItem $item): bool => $item->coach_id === $coach->id);

        Gate::define('manage-program', fn (User $coach, Program $program): bool => $coach->isCoach()
            && ($program->coach_id === $coach->id || $coach->managesClient($program->client)));

        Gate::define('manage-team', fn (User $coach): bool => $coach->isTeamAdmin());

        Gate::define('manage-import', fn (User $coach, Import $import): bool => $import->coach_id === $coach->id);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
