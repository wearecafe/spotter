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

    public int $repeatWeeks = 1;

    public string $programTitle = '';

    public string $programStart = '';

    public int $programDays = 3;

    public int $programWeeks = 6;

    /**
     * Re-checked on every action: access can change while the page is open (reassignment, removal).
     */
    public function hydrate(): void
    {
        Gate::authorize('manage-client', $this->client);
    }

    public function mount(User $client): void
    {
        Gate::authorize('manage-client', $client);

        $this->client = $client;
        $this->goal = (string) $client->goal;
        $this->coachNotes = (string) $client->coach_notes;
        $this->canReschedule = $client->can_reschedule;
        $this->scheduledOn = today()->toDateString();
        $this->archiveAccess = Auth::user()->archived_client_access ?: 'read_only';
        $this->programStart = today()->startOfWeek()->addWeek()->toDateString();
    }

    #[Computed]
    public function programs()
    {
        return $this->client->programs()->withMax('workouts', 'week')->latest()->get();
    }

    public function createProgram(\App\Services\ProgramScheduler $scheduler): void
    {
        $this->validate([
            'programTitle' => ['required', 'string', 'max:120'],
            'programStart' => ['required', 'date'],
            'programDays' => ['required', 'integer', 'between:1,7'],
            'programWeeks' => ['required', 'integer', 'between:1,52'],
        ], attributes: ['programTitle' => __('title'), 'programStart' => __('start date')]);

        $program = $scheduler->create($this->client, $this->programTitle, $this->programStart, $this->programWeeks, $this->programDays);

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
            ->with('loggedBy:id,name')
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
            $this->validate(['repeatWeeks' => ['required', 'integer', 'between:1,26']]);
            $template = Workout::findOrFail($this->templateId);
            $first = \Illuminate\Support\Carbon::parse($this->scheduledOn);

            foreach (range(0, $this->repeatWeeks - 1) as $i) {
                $workout = $template->assignTo($this->client, $first->copy()->addWeeks($i));
            }

            Flux::toast(variant: 'success', text: $this->repeatWeeks > 1
                ? __(':title scheduled every :day for :count weeks.', ['title' => $template->title, 'day' => $first->translatedFormat('l'), 'count' => $this->repeatWeeks])
                : __(':title scheduled for :date.', ['title' => $template->title, 'date' => $first->translatedFormat('D j M')]));
            $this->repeatWeeks = 1;

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

        // A program session is skipped rather than deleted, so the program doesn't put it back.
        $workout->program_day_id ? app(\App\Services\ProgramScheduler::class)->skip($workout) : $workout->delete();

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

    /**
     * Run today's session with this client (or pull their next one forward) and log it for them.
     */
    public function runSession(\App\Services\TrainerSession $sessions): void
    {
        abort_if($this->client->isArchived(), 422);

        $session = $sessions->start($this->client);

        if ($session === null) {
            Flux::toast(variant: 'warning', text: __('Nothing scheduled for :name. Build them a workout first.', ['name' => $this->client->name]));

            return;
        }

        $this->redirectRoute('coach.session', ['w' => $session->id], navigate: true);
    }

    /**
     * Switch between in-person only (no app, no invite) and an app client waiting to join.
     */
    public function setInPerson(bool $inPerson): void
    {
        abort_if($this->client->hasAcceptedInvitation(), 422);

        $this->client->update(['in_person' => $inPerson]);
    }

    public string $restrictionKey = '';

    public string $restrictionSide = 'both';

    public string $restrictionNote = '';

    public function addRestriction(): void
    {
        $this->validate([
            'restrictionKey' => ['required', Rule::in(array_keys(\App\Support\Restrictions::CATALOG))],
            'restrictionSide' => ['required', Rule::in(array_keys(\App\Support\Restrictions::SIDES))],
            'restrictionNote' => ['nullable', 'string', 'max:200'],
        ], attributes: ['restrictionKey' => __('restriction')]);

        $restrictions = collect($this->client->restrictions ?? [])
            ->reject(fn ($r) => ($r['key'] ?? null) === $this->restrictionKey) // one entry per restriction: re-adding updates it
            ->push(['key' => $this->restrictionKey, 'side' => $this->restrictionSide, 'note' => $this->restrictionNote !== '' ? $this->restrictionNote : null])
            ->values()
            ->all();

        $this->client->update(['restrictions' => $restrictions]);
        $this->reset('restrictionKey', 'restrictionSide', 'restrictionNote');
    }

    public function removeRestriction(string $key): void
    {
        $restrictions = collect($this->client->restrictions ?? [])->reject(fn ($r) => ($r['key'] ?? null) === $key)->values()->all();

        $this->client->update(['restrictions' => $restrictions ?: null]);
    }

    public bool $canReschedule = true;

    public function updatedCanReschedule(): void
    {
        $this->client->update(['can_reschedule' => $this->canReschedule]);
    }

    public string $archiveAccess = 'read_only';

    /**
     * Take the client out of day-to-day work and off next month's bill, keeping everything.
     */
    public function archive(\App\Services\ClientArchive $archive): void
    {
        $this->validate(['archiveAccess' => ['required', Rule::in(array_keys(User::ARCHIVED_ACCESS))]]);

        $archive->archive($this->client, $this->archiveAccess);
        $this->client->refresh();
        Flux::modal('archive-client')->close();

        Flux::toast(variant: 'success', text: __(':name is archived. Their history is kept and they come off your bill next month.', ['name' => $this->client->name]));
    }

    public function unarchive(\App\Services\ClientArchive $archive): void
    {
        $archive->unarchive($this->client);
        $this->client->refresh();

        Flux::toast(variant: 'success', text: __(':name is active again.', ['name' => $this->client->name]));
    }

    public function setArchivedAccess(string $access, \App\Services\ClientArchive $archive): void
    {
        abort_unless($this->client->isArchived() && array_key_exists($access, User::ARCHIVED_ACCESS), 422);

        $archive->setAccess($this->client, $access);
        $this->client->refresh();
    }

    /**
     * Permanently delete the client and everything they logged. For erasure requests; archive is the everyday option.
     */
    public function removeClient(): void
    {
        $this->client->delete();

        Flux::toast(text: __('Client deleted.'));
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

        <div class="flex flex-wrap items-center gap-2">
            @unless ($client->isArchived())
                <flux:button size="sm" variant="primary" icon="play" wire:click="runSession" data-test="run-session">{{ __('Run session') }}</flux:button>
            @endunless
            <flux:button size="sm" icon="chart-bar" :href="route('coach.clients.progress', $client)" wire:navigate data-test="progress-link">{{ __('Progress') }}</flux:button>
            <flux:button size="sm" icon="chart-pie" :href="route('coach.clients.nutrition', $client)" wire:navigate>{{ __('Nutrition') }}</flux:button>
            <flux:button size="sm" icon="chat-bubble-left-right" :href="route('coach.clients.messages', $client)" wire:navigate>{{ __('Messages') }}</flux:button>
            @php($compliance = $client->complianceRate())
            @if ($compliance !== null)
                <flux:badge :color="$compliance >= 80 ? 'green' : ($compliance >= 50 ? 'amber' : 'red')">{{ __(':rate% compliance (30 days)', ['rate' => $compliance]) }}</flux:badge>
            @endif
        </div>
    </div>

    @if ($client->isArchived())
        <flux:callout icon="archive-box" color="zinc" data-test="archived-callout">
            <flux:callout.heading>{{ $client->archived_reason === 'inactive'
                ? __('Archived :date after a stretch with no activity', ['date' => $client->archived_at->translatedFormat('j M Y')])
                : __('Archived :date', ['date' => $client->archived_at->translatedFormat('j M Y')]) }}</flux:callout.heading>
            <flux:callout.text>{{ __('Everything is kept. They’re out of your lists, inbox and Train, and stop counting toward your bill from next month.') }}</flux:callout.text>
            <div class="mt-3 max-w-xs">
                <flux:select wire:change="setArchivedAccess($event.target.value)" :label="__('While archived, they')" data-test="archived-access">
                    @foreach (\App\Models\User::ARCHIVED_ACCESS as $value => $label)
                        <flux:select.option :value="$value" :selected="$client->archived_access === $value">{{ __($label) }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <x-slot name="actions">
                <flux:button size="sm" variant="primary" wire:click="unarchive" data-test="unarchive-client">{{ __('Unarchive') }}</flux:button>
            </x-slot>
        </flux:callout>
    @elseif ($client->isInPersonOnly())
        <flux:callout icon="building-storefront" data-test="in-person-callout">
            <flux:callout.heading>{{ __(':name trains in person', ['name' => str($client->name)->before(' ')]) }}</flux:callout.heading>
            <flux:callout.text>{{ __('They don’t use the app. Run their sessions from Train and everything you log shows up here.') }}</flux:callout.text>
            <x-slot name="actions">
                <flux:button size="sm" wire:click="setInPerson(false)">{{ __('Give app access') }}</flux:button>
            </x-slot>
        </flux:callout>
    @elseif (! $client->hasAcceptedInvitation())
        <flux:callout icon="envelope" color="amber">
            <flux:callout.heading>{{ __("Waiting for :name to join", ['name' => str($client->name)->before(' ')]) }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('Send them this link so they can set a password and see their workouts:') }}
            </flux:callout.text>
            <div class="mt-2">
                <flux:input readonly copyable :value="route('invitation.accept', $client->invitation_token)" data-test="invite-link" />
            </div>
            <x-slot name="actions">
                <flux:button size="sm" variant="ghost" wire:click="setInPerson(true)">{{ __('In person only') }}</flux:button>
            </x-slot>
        </flux:callout>
    @endif

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
                <flux:heading size="lg">{{ __('Schedule a workout') }}</flux:heading>
                <flux:text size="sm">{{ __('For a multi-week plan, create a program above: it schedules every session for you.') }}</flux:text>
                <form wire:submit="schedule" class="flex flex-col gap-4 sm:flex-row sm:items-end">
                    <flux:select wire:model="templateId" :label="__('From template')" class="sm:flex-1">
                        <flux:select.option value="">{{ __('Blank workout (build from scratch)') }}</flux:select.option>
                        @foreach ($this->templates as $template)
                            <flux:select.option :value="$template->id">{{ $template->title }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model="scheduledOn" type="date" :label="__('Date')" class="sm:w-44" />
                    <flux:select wire:model="repeatWeeks" :label="__('Repeat')" class="sm:w-40">
                        <flux:select.option value="1">{{ __('Just once') }}</flux:select.option>
                        @foreach ([2, 3, 4, 6, 8, 12] as $n)
                            <flux:select.option :value="$n">{{ __('Weekly for :n weeks', ['n' => $n]) }}</flux:select.option>
                        @endforeach
                    </flux:select>
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
                                <flux:text size="sm">
                                    {{ trans_choice(':count exercise|:count exercises', $workout->items_count) }}
                                    @if ($workout->moved_from)
                                        · {{ __('moved from :day', ['day' => $workout->moved_from->translatedFormat('D j M')]) }}
                                    @endif
                                </flux:text>
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
                        @if ($workout->loggedBy)
                            <flux:text size="sm" class="ml-23">{{ __('Logged by :name', ['name' => $workout->loggedBy->name]) }}</flux:text>
                        @endif
                        @if ($workout->session_notes)
                            <flux:text size="sm" class="ml-23 whitespace-pre-line">{{ $workout->session_notes }}</flux:text>
                        @endif
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

            <flux:card class="flex flex-col gap-3" data-test="restrictions">
                <div>
                    <flux:heading>{{ __('Injuries and restrictions') }}</flux:heading>
                    <flux:text size="sm">{{ __('Exercises that run into these are flagged when you build :name’s workouts and programs.', ['name' => str($client->name)->before(' ')]) }}</flux:text>
                </div>
                @foreach (\App\Support\Restrictions::forClient($client) as $restriction)
                    <div class="flex items-start justify-between gap-2 rounded-lg bg-amber-50 px-3 py-2 dark:bg-amber-500/10" wire:key="restriction-{{ $restriction['key'] }}">
                        <div class="text-sm">
                            <div class="font-medium text-zinc-900 dark:text-white">{{ \App\Support\Restrictions::describe($restriction) }}</div>
                            @if ($restriction['note'])
                                <div class="text-zinc-600 dark:text-zinc-300">{{ $restriction['note'] }}</div>
                            @endif
                        </div>
                        <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="removeRestriction('{{ $restriction['key'] }}')" :aria-label="__('Remove')" />
                    </div>
                @endforeach
                <form wire:submit="addRestriction" class="flex flex-col gap-2">
                    <flux:select wire:model="restrictionKey" size="sm" :aria-label="__('Restriction')" data-test="restriction-key">
                        <flux:select.option value="">{{ __('Add a restriction…') }}</flux:select.option>
                        @foreach (\App\Support\Restrictions::CATALOG as $key => $rule)
                            <flux:select.option :value="$key">{{ __($rule['area']) }}: {{ __($rule['label']) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <div class="grid grid-cols-[7rem_minmax(0,1fr)] gap-2">
                        <flux:select wire:model="restrictionSide" size="sm" :aria-label="__('Side')">
                            @foreach (\App\Support\Restrictions::SIDES as $value => $label)
                                <flux:select.option :value="$value">{{ __($label) }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:input wire:model="restrictionNote" size="sm" :placeholder="__('Note (optional)')" :aria-label="__('Note')" />
                    </div>
                    <flux:error name="restrictionKey" />
                    <flux:button type="submit" size="sm" class="self-start">{{ __('Add') }}</flux:button>
                </form>
                <flux:text size="sm" class="text-zinc-500">{{ __('Flags are matched on exercise names, so use your judgement too.') }}</flux:text>
            </flux:card>

            <livewire:pages::habits.board :client="$client" :key="'habits-'.$client->id" />

            <flux:card>
                <form wire:submit="saveProfile" class="flex flex-col gap-4">
                    <flux:heading size="lg">{{ __('Profile') }}</flux:heading>
                    <flux:input wire:model="goal" :label="__('Goal')" />
                    <flux:switch wire:model.live="canReschedule"
                        :label="__('Can move sessions')" :description="__('Lets them move a session to another day in the same week. You’ll see it in your inbox.')" data-test="can-reschedule" />
                    <flux:textarea wire:model="coachNotes" :label="__('Private notes')" :description="__('Only you can see these.')" rows="6" />
                    <flux:button type="submit" class="self-start">{{ __('Save') }}</flux:button>
                </form>
            </flux:card>

            @unless ($client->isArchived())
                <flux:card class="flex flex-col gap-3">
                    <flux:heading>{{ __('Archive client') }}</flux:heading>
                    <flux:text size="sm">{{ __('Stopped coaching them? Archive keeps every workout, check-in and message, takes them out of your lists and inbox, and stops them counting toward your bill from next month. You can unarchive any time.') }}</flux:text>
                    <flux:modal.trigger name="archive-client">
                        <flux:button size="sm" icon="archive-box" class="self-start" data-test="archive-client">{{ __('Archive') }}</flux:button>
                    </flux:modal.trigger>
                </flux:card>
            @endunless

            <flux:card class="flex flex-col gap-3">
                <flux:heading>{{ __('Delete permanently') }}</flux:heading>
                <flux:text size="sm">{{ __('Only for when they ask you to erase their data. Deletes their account and all of their history for good.') }}</flux:text>
                <flux:button variant="danger" size="sm" class="self-start" wire:click="removeClient" wire:confirm="{{ __('Delete :name and all their history? This cannot be undone. To stop coaching them but keep their history, archive them instead.', ['name' => $client->name]) }}" data-test="delete-client">{{ __('Delete client') }}</flux:button>
            </flux:card>
        </div>
    </div>

    <flux:modal name="archive-client" class="md:w-96">
        <form wire:submit="archive" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Archive :name?', ['name' => $client->name]) }}</flux:heading>
                <flux:text class="mt-2">{{ __('Their workouts, check-ins and messages are all kept. They leave your client list, inbox and Train, and stop counting toward your bill from next month.') }}</flux:text>
            </div>

            <flux:radio.group wire:model="archiveAccess" :label="__('While archived, they')">
                @foreach (\App\Models\User::ARCHIVED_ACCESS as $value => $label)
                    <flux:radio :value="$value" :label="__($label)" />
                @endforeach
            </flux:radio.group>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="confirm-archive">{{ __('Archive') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
