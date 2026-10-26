<?php

use App\Concerns\PasswordValidationRules;
use App\Enums\Role;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\TeamManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::auth')] #[Title('Join a team')] class extends Component {
    use PasswordValidationRules;

    #[Locked]
    public string $token = '';

    public string $name = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        $invitation = $this->invitation();

        if (! Auth::check() && $this->accountFor($invitation->email)) {
            // Bring them back here after they log in.
            session()->put('url.intended', route('team-invitation.accept', $token));
        }
    }

    /**
     * Re-read on every action so a used or revoked invitation can't be replayed.
     */
    protected function invitation(): TeamInvitation
    {
        return TeamInvitation::query()->pending()->where('token', $this->token)->with('team')->firstOrFail();
    }

    protected function accountFor(string $email): bool
    {
        return User::whereRaw('lower(email) = ?', [Str::lower($email)])->exists();
    }

    public function existingAccount(): bool
    {
        return $this->accountFor($this->invitation()->email);
    }

    /**
     * Why the signed-in user can't accept, if they can't.
     */
    public function blocker(): ?string
    {
        $user = Auth::user();
        $invitation = $this->invitation();

        if (! $user) {
            return null;
        }

        if (Str::lower($user->email) !== Str::lower($invitation->email)) {
            return __('This invitation was sent to :email. Log out and sign in with that account to accept it.', ['email' => $invitation->email]);
        }

        if (! $user->isCoach()) {
            return __('Client accounts can’t join a coaching team.');
        }

        if ($user->team_id === $invitation->team_id) {
            return __('You’re already on this team.');
        }

        // Clients only travel with a coach who works alone; a gym's clients stay with the gym.
        if (User::where('team_id', $user->team_id)->where('role', Role::Coach)->where('id', '!=', $user->id)->exists()) {
            return $user->isTeamOwner()
                ? __('You own a team with other coaches. Remove them first, or ask Spotter support to merge teams.')
                : __('You’re a coach on :team. Ask an admin there to remove you first; your clients stay with them.', ['team' => $user->team?->name]);
        }

        return null;
    }

    public function join(TeamManager $manager): void
    {
        abort_unless(Auth::check() && $this->blocker() === null, 403);

        $manager->accept($this->invitation(), Auth::user());

        $this->redirectRoute('coach.dashboard', navigate: true);
    }

    public function register(TeamManager $manager): void
    {
        $invitation = $this->invitation();
        abort_if(Auth::check() || $this->existingAccount(), 403);

        $this->validate(['name' => ['required', 'string', 'max:255'], 'password' => $this->passwordRules()]);

        $coach = new User;
        $coach->forceFill([
            'name' => $this->name,
            'email' => Str::lower($invitation->email),
            'password' => $this->password,
            'role' => Role::Coach,
            'team_id' => $invitation->team_id, // set before saving so no solo workspace is created
            'team_role' => $invitation->role,
            'email_verified_at' => now(), // they proved the address by opening the emailed link
        ])->save();

        $manager->accept($invitation, $coach);
        Auth::login($coach);

        $this->redirectRoute('coach.dashboard', navigate: true);
    }
}; ?>

@php($invitation = $this->invitation())
<div class="flex flex-col gap-6">
    <x-auth-header
        :title="__('Join :team', ['team' => $invitation->team->name])"
        :description="__('You’ve been invited to coach on Spotter as :role.', ['role' => Str::lower(__(\App\Models\Team::ROLES[$invitation->role]))])"
    />

    @auth
        @if ($blocker = $this->blocker())
            <flux:callout icon="exclamation-triangle" color="amber"><flux:callout.text>{{ $blocker }}</flux:callout.text></flux:callout>
        @else
            <flux:text>{{ __('Your clients, programs and templates come with you.') }}</flux:text>
            <flux:button variant="primary" class="w-full" wire:click="join" data-test="join-team">{{ __('Join :team', ['team' => $invitation->team->name]) }}</flux:button>
        @endif
    @else
        @if ($this->existingAccount())
            <flux:text>{{ __('You already have a Spotter account with :email. Log in to accept.', ['email' => $invitation->email]) }}</flux:text>
            <flux:button variant="primary" class="w-full" :href="route('login')">{{ __('Log in to accept') }}</flux:button>
        @else
            <form wire:submit="register" class="flex flex-col gap-6">
                <flux:input :label="__('Email address')" :value="$invitation->email" type="email" disabled />
                <flux:input wire:model="name" :label="__('Your name')" required autofocus autocomplete="name" />
                <flux:input wire:model="password" :label="__('Password')" type="password" required autocomplete="new-password" viewable />
                <flux:input wire:model="password_confirmation" :label="__('Confirm password')" type="password" required autocomplete="new-password" viewable />
                <flux:button type="submit" variant="primary" class="w-full" data-test="create-coach-account">{{ __('Create account and join') }}</flux:button>
            </form>
        @endif
    @endauth
</div>
