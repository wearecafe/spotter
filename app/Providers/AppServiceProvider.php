<?php

namespace App\Providers;

use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\TenantGuard;
use App\Models\Import;
use App\Models\InboxItem;
use App\Models\Program;
use App\Models\User;
use App\Support\Demo;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Livewire\Component;
use Livewire\Livewire;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

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

        // Re-apply these on every Livewire action, not only on the page load.
        Livewire::addPersistentMiddleware([EnsureUserHasRole::class, EnsureStaff::class, TenantGuard::class]);

        // A coach manages their own clients; team owners and admins manage the whole team's.
        Gate::define('manage-client', fn (User $coach, User $client): bool => $coach->managesClient($client));

        Gate::define('manage-inbox-item', fn (User $coach, InboxItem $item): bool => $item->coach_id === $coach->id && $coach->managesClient($item->client));

        Gate::define('manage-program', fn (User $coach, Program $program): bool => $coach->managesClient($program->client));

        Gate::define('manage-team', fn (User $coach): bool => $coach->isTeamAdmin());

        Gate::define('manage-import', fn (User $coach, Import $import): bool => $import->coach_id === $coach->id);

        $this->protectDemo();
    }

    /**
     * Livewire actions the shared demo accounts can't run: anything on the
     * settings pages (profile, password, two-factor, deleting the account)
     * and the actions that would email real people.
     *
     * @var array<string, list<string>|'*'>
     */
    protected const DEMO_BLOCKED_ACTIONS = [
        'pages::settings.profile' => '*',
        'pages::settings.security' => '*',
        'pages::settings.delete-user-modal' => '*',
        'pages::settings.delete-user-form' => '*',
        'pages::settings.two-factor-setup-modal' => '*',
        'pages::settings.two-factor.recovery-codes' => '*',
        'pages::coach.team' => ['invite'],
        'pages::coach.setup' => ['sendInvites'],
    ];

    /**
     * Keep the demo gym from changing its shared accounts or reaching the outside world.
     */
    protected function protectDemo(): void
    {
        Livewire::listen('call', function (Component $component, string $method, $params, $context, callable $returnEarly) {
            if (str_starts_with($method, '$') || str_starts_with($method, '__') || ! Demo::isDemoUser(Auth::user())) {
                return;
            }

            $blocked = self::DEMO_BLOCKED_ACTIONS[$component->getName()] ?? [];

            if ($blocked === '*' || in_array($method, $blocked, true)) {
                Flux::toast(variant: 'warning', text: Str::startsWith($component->getName(), 'pages::settings.')
                    ? __('Account settings can’t be changed in the demo.')
                    : __('The demo never sends email. Start your own free account to invite people.'));
                $returnEarly();
            }
        });

        // Nothing is ever emailed to a demo address, and nothing leaves while someone is signed in as a demo account.
        Event::listen(MessageSending::class, function (MessageSending $event) {
            $message = $event->message;

            if (! $message instanceof Email) {
                return null;
            }

            $recipients = collect([...$message->getTo(), ...$message->getCc(), ...$message->getBcc()])
                ->map(fn (Address $address) => $address->getAddress());

            if (Demo::isDemoUser(Auth::user()) || $recipients->contains(fn (string $email) => Demo::isDemoEmail($email))) {
                return false;
            }

            return null;
        });
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
