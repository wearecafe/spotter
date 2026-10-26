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

    public string $programTitle = '';

    public string $programStart = '';

    public int $programDays = 3;

    public int $programWeeks = 6;

    public function mount(User $client): void
    {
        Gate::authorize('manage-client', $client);

        $this->client = $client;
        $this->goal = (string) $client->goal;
        $this->coachNotes = (string) $client->coach_notes;
        $this->scheduledOn = today()->toDateString();
        $this->programStart = today()->startOfWeek()->addWeek()->toDateString();
    }

    #[Computed]
    public function programs()
    {
        return $this->client->programs()->withMax('workouts', 'week')->latest()->get();
    }

    public function createProgram(\App\Services\Progression $progression): void
    {
        $this->validate([
            'programTitle' => ['required', 'string', 'max:120'],
            'programStart' => ['required', 'date'],
            'programDays' => ['required', 'integer', 'between:1,7'],
            'programWeeks' => ['required', 'integer', 'between:1,52'],
        ], attributes: ['programTitle' => __('title'), 'programStart' => __('start date')]);

        $program = \App\Models\Program::create([
            'coach_id' => $this->client->coach_id,
            'client_id' => $this->client->id,
            'title' => $this->programTitle,
            'starts_on' => $this->programStart,
            'planned_weeks' => $this->programWeeks,
        ]);

        $progression->start($program, $this->programDays);

        $this->redirectRoute('coach.programs.show', $program, navigate: true);
    }

    #[Computed]
    public function templates()
    {
        // Templates from anyone on the team.
        return Workout::query()->templates()->whereIn('coach_id', Auth::user()->teamCoachIds())->orderBy('title')->get(['id', 'title']);
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
            'templateId' => ['nullable', Rule::exists('workouts', 'id')->whereIn('coach_id', Auth::user()->teamCoachIds())->whereNull('client_id')],
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
            'coach_id' => $this->client->coach_id,
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

    #[Computed]
    public function teamCoaches()
    {
        return Auth::user()->isTeamAdmin()
            ? \App\Models\User::query()->whereIn('id', Auth::user()->teamCoachIds())->orderBy('name')->get(['id', 'name'])
            : collect();
    }

    public function reassign(int $coachId, \App\Services\TeamManager $team): void
    {
        abort_unless(Auth::user()->isTeamAdmin(), 403);
        $coach = \App\Models\User::query()->whereKey($coachId)->whereIn('id', Auth::user()->teamCoachIds())->firstOrFail();

        $team->reassign($this->client, $coach);
        $this->client->refresh();

        Flux::toast(variant: 'success', text: __(':client is now coached by :coach.', ['client' => $this->client->name, 'coach' => $coach->name]));
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
            <flux:button size="sm" icon="chat-bubble-left-right" :href="route('coach.clients.messages', $client)" wire:navigate>{{ __('Messages') }}</flux:button>
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
                <flux:heading size="lg">{{ __('Programs') }}</flux:heading>
                @foreach ($this->programs as $program)
                    <a href="{{ route('coach.programs.show', $program) }}" wire:navigate wire:key="program-{{ $program->id }}" class="flex items-center justify-between gap-3 rounded-lg border border-zinc-200 px-4 py-3 hover:border-zinc-400 dark:border-zinc-700">
                        <span class="font-semibold text-zinc-900 dark:text-white">{{ $program->title }}</span>
                        <flux:text size="sm">{{ __('Week :week of :planned', ['week' => $program->workouts_max_week ?? 1, 'planned' => $program->planned_weeks]) }}</flux:text>
                    </a>
                @endforeach
                <form wire:submit="createProgram" class="grid gap-3 sm:grid-cols-[1fr_10rem_6rem_6rem_auto] sm:items-end">
                    <flux:input wire:model="programTitle" :label="__('New program')" :placeholder="__('Strength block')" />
                    <flux:input wire:model="programStart" type="date" :label="__('Starts')" />
                    <flux:input wire:model="programDays" type="number" min="1" max="7" :label="__('Days/wk')" />
                    <flux:input wire:model="programWeeks" type="number" min="1" max="52" :label="__('Weeks')" />
                    <flux:button type="submit" variant="primary" data-test="create-program">{{ __('Create') }}</flux:button>
                </form>
            </flux:card>

            <flux:card class="flex flex-col gap-4">
                <flux:heading size="lg">{{ __('Schedule a single workout') }}</flux:heading>
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
            @if ($this->teamCoaches->count() > 1)
                <flux:card class="flex flex-col gap-3">
                    <flux:heading>{{ __('Coach') }}</flux:heading>
                    <flux:select wire:change="reassign($event.target.value)" :aria-label="__('Assigned coach')">
                        @foreach ($this->teamCoaches as $coach)
                            <flux:select.option :value="$coach->id" :selected="$coach->id === $client->coach_id">{{ $coach->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:text size="sm">{{ __('Upcoming workouts, programs and open inbox items move with the client.') }}</flux:text>
                </flux:card>
            @endif

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
