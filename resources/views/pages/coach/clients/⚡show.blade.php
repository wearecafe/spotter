<?php

use App\Models\User;
use App\Models\Workout;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Client')] class extends Component {
    public User $client;

    public string $goal = '';

    public string $coachNotes = '';

    public string $templateId = '';

    public string $scheduledOn = '';

    public function mount(User $client): void
    {
        Gate::authorize('manage-client', $client);

        $this->client = $client;
        $this->goal = (string) $client->goal;
        $this->coachNotes = (string) $client->coach_notes;
        $this->scheduledOn = today()->toDateString();
    }

    #[Computed]
    public function templates()
    {
        return Auth::user()->templates()->orderBy('title')->get(['id', 'title']);
    }

    #[Computed]
    public function upcoming()
    {
        return $this->client->assignedWorkouts()
            ->whereNull('completed_at')
            ->where('scheduled_on', '>=', today())
            ->withCount('items')
            ->orderBy('scheduled_on')
            ->get();
    }

    #[Computed]
    public function history()
    {
        return $this->client->assignedWorkouts()
            ->where(fn ($q) => $q->whereNotNull('completed_at')->orWhere('scheduled_on', '<', today()))
            ->orderByDesc('scheduled_on')
            ->limit(20)
            ->get();
    }

    public function saveProfile(): void
    {
        $this->validate([
            'goal' => ['nullable', 'string', 'max:255'],
            'coachNotes' => ['nullable', 'string', 'max:5000'],
        ]);

        $this->client->update(['goal' => $this->goal ?: null, 'coach_notes' => $this->coachNotes ?: null]);

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    public function schedule(): void
    {
        $this->validate([
            'scheduledOn' => ['required', 'date'],
            'templateId' => ['nullable', Rule::exists('workouts', 'id')->where('coach_id', Auth::id())->whereNull('client_id')],
        ]);

        if ($this->templateId) {
            $workout = Workout::findOrFail($this->templateId)->assignTo($this->client, $this->scheduledOn);

            Flux::toast(variant: 'success', text: __(':title scheduled for :date.', [
                'title' => $workout->title,
                'date' => $workout->scheduled_on->format('D, M j'),
            ]));

            $this->templateId = '';
            unset($this->upcoming);

            return;
        }

        $workout = Workout::create([
            'coach_id' => Auth::id(),
            'client_id' => $this->client->id,
            'title' => __('New workout'),
            'scheduled_on' => $this->scheduledOn,
        ]);

        $this->redirectRoute('coach.workouts.edit', $workout, navigate: true);
    }

    public function deleteWorkout(int $workoutId): void
    {
        $workout = $this->client->assignedWorkouts()->findOrFail($workoutId);
        Gate::authorize('delete', $workout);
        $workout->delete();

        unset($this->upcoming, $this->history);
    }

    public function removeClient(): void
    {
        $this->client->delete();

        Flux::toast(text: __('Client removed.'));
        $this->redirectRoute('coach.clients.index', navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-center gap-4">
            <flux:avatar size="lg" :name="$client->name" :initials="$client->initials()" />
            <div>
                <flux:heading size="xl" level="1">{{ $client->name }}</flux:heading>
                <flux:text>{{ $client->email }}</flux:text>
            </div>
        </div>

        <div class="flex items-center gap-2">
            @php($compliance = $client->complianceRate())
            @if ($compliance !== null)
                <flux:badge :color="$compliance >= 80 ? 'green' : ($compliance >= 50 ? 'amber' : 'red')">{{ __(':rate% compliance (30 days)', ['rate' => $compliance]) }}</flux:badge>
            @endif
        </div>
    </div>

    @unless ($client->hasAcceptedInvitation())
        <flux:callout icon="envelope" color="amber">
            <flux:callout.heading>{{ __("Waiting for :name to join", ['name' => str($client->name)->before(' ')]) }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('Send them this link so they can set a password and see their workouts:') }}
            </flux:callout.text>
            <div class="mt-2">
                <flux:input readonly copyable :value="route('invitation.accept', $client->invitation_token)" data-test="invite-link" />
            </div>
        </flux:callout>
    @endunless

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="flex flex-col gap-6 lg:col-span-2">
            <flux:card class="flex flex-col gap-4">
                <flux:heading size="lg">{{ __('Schedule a workout') }}</flux:heading>
                <form wire:submit="schedule" class="flex flex-col gap-4 sm:flex-row sm:items-end">
                    <flux:select wire:model="templateId" :label="__('From template')" class="sm:flex-1">
                        <flux:select.option value="">{{ __('Blank workout (build from scratch)') }}</flux:select.option>
                        @foreach ($this->templates as $template)
                            <flux:select.option :value="$template->id">{{ $template->title }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model="scheduledOn" type="date" :label="__('Date')" class="sm:w-44" />
                    <flux:button type="submit" variant="primary" data-test="schedule-workout-button">{{ __('Schedule') }}</flux:button>
                </form>
            </flux:card>

            <flux:card class="flex flex-col gap-4">
                <flux:heading size="lg">{{ __('Upcoming') }}</flux:heading>
                @forelse ($this->upcoming as $workout)
                    <div class="flex items-center justify-between gap-3" wire:key="upcoming-{{ $workout->id }}">
                        <div class="flex items-center gap-4">
                            <div class="w-16 shrink-0 text-center">
                                <div class="text-xs uppercase text-zinc-500">{{ $workout->scheduled_on->format('D') }}</div>
                                <div class="text-lg font-semibold text-zinc-900 dark:text-white">{{ $workout->scheduled_on->format('M j') }}</div>
                            </div>
                            <div>
                                <a href="{{ route('coach.workouts.edit', $workout) }}" wire:navigate class="font-medium text-zinc-900 hover:underline dark:text-white">{{ $workout->title }}</a>
                                <flux:text size="sm">{{ trans_choice(':count exercise|:count exercises', $workout->items_count) }}</flux:text>
                            </div>
                        </div>
                        <div class="flex gap-1">
                            <flux:button size="sm" variant="ghost" icon="pencil-square" :href="route('coach.workouts.edit', $workout)" wire:navigate />
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteWorkout({{ $workout->id }})" wire:confirm="{{ __('Remove this workout from the calendar?') }}" />
                        </div>
                    </div>
                @empty
                    <flux:text>{{ __('Nothing scheduled. Pick a template above to get them moving.') }}</flux:text>
                @endforelse
            </flux:card>

            <flux:card class="flex flex-col gap-4">
                <flux:heading size="lg">{{ __('History') }}</flux:heading>
                @forelse ($this->history as $workout)
                    <div class="flex flex-col gap-1" wire:key="history-{{ $workout->id }}">
                        <div class="flex items-center justify-between gap-3">
                            <div class="flex items-center gap-3">
                                <span class="w-20 shrink-0 text-sm text-zinc-500">{{ $workout->scheduled_on?->format('M j') }}</span>
                                <a href="{{ route('coach.workouts.edit', $workout) }}" wire:navigate class="font-medium text-zinc-900 hover:underline dark:text-white">{{ $workout->title }}</a>
                            </div>
                            @if ($workout->isCompleted())
                                <flux:badge size="sm" color="green" icon="check">{{ __('Done') }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="red">{{ __('Missed') }}</flux:badge>
                            @endif
                        </div>
                        @if ($workout->client_feedback)
                            <flux:text size="sm" class="ml-23 italic">“{{ $workout->client_feedback }}”</flux:text>
                        @endif
                    </div>
                @empty
                    <flux:text>{{ __('No workouts logged yet.') }}</flux:text>
                @endforelse
            </flux:card>
        </div>

        <div class="flex flex-col gap-6">
            <flux:card>
                <form wire:submit="saveProfile" class="flex flex-col gap-4">
                    <flux:heading size="lg">{{ __('Profile') }}</flux:heading>
                    <flux:input wire:model="goal" :label="__('Goal')" />
                    <flux:textarea wire:model="coachNotes" :label="__('Private notes')" :description="__('Only you can see these.')" rows="6" />
                    <flux:button type="submit" class="self-start">{{ __('Save') }}</flux:button>
                </form>
            </flux:card>

            <flux:card class="flex flex-col gap-3">
                <flux:heading>{{ __('Remove client') }}</flux:heading>
                <flux:text size="sm">{{ __('Deletes their account and all of their workout history.') }}</flux:text>
                <flux:button variant="danger" size="sm" class="self-start" wire:click="removeClient" wire:confirm="{{ __('Remove :name and all their history? This cannot be undone.', ['name' => $client->name]) }}">{{ __('Remove client') }}</flux:button>
            </flux:card>
        </div>
    </div>
</div>
