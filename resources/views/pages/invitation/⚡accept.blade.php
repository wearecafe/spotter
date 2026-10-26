<?php

use App\Concerns\PasswordValidationRules;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::auth')] #[Title('Join your coach')] class extends Component {
    use PasswordValidationRules;

    public User $client;

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->client = User::query()
            ->where('role', Role::Client)
            ->where('invitation_token', $token)
            ->whereNull('invitation_accepted_at')
            ->with('coach')
            ->firstOrFail();
    }

    public function accept(): void
    {
        $this->validate(['password' => $this->passwordRules()]);

        $this->client->forceFill([
            'password' => $this->password,
            'invitation_token' => null,
            'invitation_accepted_at' => now(),
            'email_verified_at' => $this->client->email_verified_at ?? now(),
        ])->save();

        Auth::login($this->client);

        $this->redirectRoute('training.index', navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header
        :title="__('Welcome, :name', ['name' => $client->name])"
        :description="__(':coach invited you to train with them on Spotter. Set a password to get started.', ['coach' => $client->coach?->name ?? __('Your coach')])"
    />

    <form wire:submit="accept" class="flex flex-col gap-6">
        <flux:input :label="__('Email address')" :value="$client->email" type="email" disabled />

        <flux:input
            wire:model="password"
            :label="__('Password')"
            type="password"
            required
            autofocus
            autocomplete="new-password"
            viewable
        />

        <flux:input
            wire:model="password_confirmation"
            :label="__('Confirm password')"
            type="password"
            required
            autocomplete="new-password"
            viewable
        />

        <flux:button type="submit" variant="primary" class="w-full" data-test="accept-invitation-button">
            {{ __('Start training') }}
        </flux:button>
    </form>
</div>
