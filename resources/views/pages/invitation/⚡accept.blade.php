<?php

use App\Concerns\PasswordValidationRules;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::invite')] #[Title('Join your coach')] class extends Component {
    use PasswordValidationRules;

    public User $client;

    public string $lang = 'en';

    public bool $showForm = false;

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

        $this->lang = session('locale', $this->client->locale ?: 'en');
        app()->setLocale($this->lang);
    }

    public function hydrate(): void
    {
        app()->setLocale($this->lang);
    }

    public function switchLanguage(string $lang): void
    {
        abort_unless(in_array($lang, ['en', 'fr'], true), 400);

        $this->lang = $lang;
        session(['locale' => $lang]);
        $this->client->update(['locale' => $lang]);
        app()->setLocale($lang);
    }

    public function isSwitch(): bool
    {
        return in_array($this->client->coach?->import_source, ['truecoach', 'trainerize', 'sheets'], true);
    }

    public function hasProgram(): bool
    {
        return $this->client->assignedWorkouts()->exists();
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

@php($coach = $client->coach)
@php($brand = $coach?->brand_color ?: '#0A7A55')
<div class="flex min-h-svh flex-col sm:min-h-0">
    <header class="flex flex-col gap-5 px-5 pb-7 pt-5 text-white" style="background: {{ $brand }}">
        <div class="flex items-center justify-between gap-3">
            <span class="font-display text-base font-extrabold">{{ $coach?->brandName() ?? 'Spotter' }}</span>
            <div role="group" aria-label="{{ __('Language') }}" class="flex rounded-full bg-black/20 p-0.5">
                @foreach (['en' => 'EN', 'fr' => 'FR'] as $code => $label)
                    <button type="button" wire:click="switchLanguage('{{ $code }}')" aria-pressed="{{ $lang === $code ? 'true' : 'false' }}"
                        @class(['h-8 min-w-11 rounded-full px-3 text-sm font-bold', 'bg-white' => $lang === $code, 'text-white' => $lang !== $code])
                        @if ($lang === $code) style="color: {{ $brand }}" @endif>{{ $label }}</button>
                @endforeach
            </div>
        </div>
        <div class="flex items-center gap-3">
            <span class="flex size-14 items-center justify-center rounded-full bg-white text-lg font-extrabold" style="color: {{ $brand }}">{{ $coach?->initials() }}</span>
            <span class="flex flex-col">
                <span class="font-bold">{{ $coach?->name }}</span>
                <span class="text-sm opacity-90">{{ __('Your coach') }}</span>
            </span>
        </div>
        <h1 class="font-display text-[28px] font-extrabold leading-tight tracking-tight">
            {{ $this->isSwitch() ? __('Your coaching is moving to Spotter') : __(':coach invited you to train on Spotter', ['coach' => str($coach?->name)->before(' ')]) }}
        </h1>
    </header>

    <main class="flex flex-1 flex-col gap-3 px-4 py-5">
        @if ($showForm)
            <form wire:submit="accept" class="flex flex-col gap-5 rounded-2xl bg-white p-5">
                <div>
                    <h2 class="font-display text-xl font-bold">{{ __('Set your password') }}</h2>
                    <p class="mt-1 text-sm text-zinc-600">{{ $client->email }}</p>
                </div>
                <flux:input wire:model="password" :label="__('Password')" type="password" required autofocus autocomplete="new-password" viewable />
                <flux:input wire:model="password_confirmation" :label="__('Confirm password')" type="password" required autocomplete="new-password" viewable />
                <button type="submit" class="h-13 rounded-2xl text-base font-bold text-white" style="background: {{ $brand }}" data-test="accept-invitation-button">{{ __('Start training') }}</button>
            </form>
        @else
            <section class="flex flex-col gap-3 rounded-2xl bg-white p-5">
                <h2 class="text-xs font-bold uppercase tracking-wide text-zinc-600">{{ __('Already set up for you') }}</h2>
                @foreach (array_filter([
                    $this->hasProgram() ? [__('Your program'), __('Same exercises, same loads. Nothing to redo.')] : [__('Your workouts'), __(':coach will add them here.', ['coach' => str($coach?->name)->before(' ')])],
                    [__('Log every set'), __('Reps, load and effort, with a rest timer and demo videos.')],
                    [__('Your weekly check-in'), __('Takes about 2 minutes, right from your phone.')],
                ]) as [$head, $body])
                    <div class="flex items-start gap-3">
                        <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-brand/15 text-xs font-extrabold text-brand" aria-hidden="true">✓</span>
                        <span class="flex flex-col">
                            <span class="font-semibold">{{ $head }}</span>
                            <span class="text-sm leading-snug text-zinc-600">{{ $body }}</span>
                        </span>
                    </div>
                @endforeach
            </section>

            @if ($this->isSwitch())
                <section class="flex flex-col gap-1.5 rounded-2xl bg-white p-5">
                    <h2 class="font-bold">{{ __('What about your old app?') }}</h2>
                    <p class="text-sm leading-relaxed text-zinc-700">{{ __('Your past workout logs couldn’t be moved, but :coach has your current program and loads.', ['coach' => str($coach?->name)->before(' ')]) }}</p>
                </section>
            @endif
        @endif
    </main>

    @unless ($showForm)
        <footer class="flex flex-col gap-2 border-t border-zinc-200 bg-white px-4 pb-6 pt-4">
            <button type="button" wire:click="$set('showForm', true)" class="h-13 rounded-2xl text-base font-bold text-white" style="background: {{ $brand }}" data-test="get-started">{{ __('Get started') }}</button>
            <p class="text-center text-sm text-zinc-600">{{ __('Opens in your browser, or add it to your home screen') }}</p>
        </footer>
    @endunless
</div>
