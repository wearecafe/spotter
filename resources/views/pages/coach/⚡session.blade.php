<?php

use App\Models\User;
use App\Models\Workout;
use App\Services\TrainerSession;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Session')] class extends Component {
    public const RPE_OPTIONS = ['6', '6.5', '7', '7.5', '8', '8.5', '9', '9.5', '10'];

    /** Workout ids in this session, comma-separated (kept in the URL so a refresh or a dropped phone resumes it). */
    #[Url(as: 'w')]
    public string $w = '';

    /** @var array<int, array<int, array<int, array{reps: string|null, load: string|null, rpe: string|null, done: bool}>>> workout id => item id => sets */
    public array $logs = [];

    /** @var array<int, string> workout id => session notes */
    public array $notes = [];

    public string $addSearch = '';

    public function mount(): void
    {
        $this->loadLogs();

        if ($this->sessions->isEmpty()) {
            $this->redirectRoute('coach.train', navigate: true);
        }
    }

    /**
     * @return list<int>
     */
    protected function ids(): array
    {
        return collect(explode(',', $this->w))->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
    }

    /**
     * @return Collection<int, Workout>
     */
    /**
     * Re-checked on every request, so a client reassigned or removed mid-session drops out.
     */
    #[Computed]
    public function sessions(): Collection
    {
        $ids = $this->ids();

        return Workout::query()
            ->whereIn('id', $ids)
            ->whereNotNull('client_id')
            ->with('client', 'items.exercise', 'loggedBy:id,name')
            ->get()
            ->filter(fn (Workout $w) => Gate::allows('run', $w))
            ->sortBy(fn (Workout $w) => array_search($w->id, $ids, true))
            ->values();
    }

    /**
     * @return array<int, array<int, string>> workout id => exercise id => last time
     */
    #[Computed]
    public function lastTimes(): array
    {
        $service = app(TrainerSession::class);

        return $this->sessions->mapWithKeys(fn (Workout $w) => [$w->id => $service->lastTime($w)])->all();
    }

    protected function loadLogs(): void
    {
        foreach ($this->sessions as $session) {
            foreach ($session->items as $item) {
                $this->logs[$session->id][$item->id] ??= $item->setsForLogging();
            }
            $this->notes[$session->id] ??= (string) $session->session_notes;
        }
    }

    protected function session(int $workoutId): Workout
    {
        return $this->sessions->firstWhere('id', $workoutId) ?? abort(404);
    }

    /**
     * Every change saves straight away, so nothing is lost if the phone locks between sets.
     */
    public function updatedLogs(mixed $value, string $key): void
    {
        [$workoutId, $itemId] = array_map('intval', array_slice(explode('.', $key), 0, 2));
        $session = $this->session($workoutId);
        $item = $session->items->firstWhere('id', $itemId) ?? abort(404);

        $this->validate([
            "logs.$workoutId.$itemId.*.reps" => ['nullable', 'string', 'max:30'],
            "logs.$workoutId.$itemId.*.load" => ['nullable', 'string', 'max:30'],
            "logs.$workoutId.$itemId.*.rpe" => ['nullable', 'in:'.implode(',', self::RPE_OPTIONS)],
            "logs.$workoutId.$itemId.*.done" => ['boolean'],
        ]);

        app(TrainerSession::class)->saveSets($session, $item, $this->logs[$workoutId][$itemId] ?? [], Auth::user());
    }

    public function updatedNotes(mixed $value, string $key): void
    {
        $workoutId = (int) $key;
        $this->validate(["notes.$workoutId" => ['nullable', 'string', 'max:2000']]);

        app(TrainerSession::class)->saveNotes($this->session($workoutId), $this->notes[$workoutId] ?? null, Auth::user());
    }

    public function complete(int $workoutId, TrainerSession $service): void
    {
        $session = $this->session($workoutId);

        if (! $session->isCompleted()) {
            $service->complete($session, Auth::user());
        }

        unset($this->sessions);
        Flux::toast(variant: 'success', text: __(':name’s session is logged.', ['name' => str($session->client->name)->before(' ')]));
    }

    public function completeAll(TrainerSession $service): void
    {
        foreach ($this->sessions as $session) {
            if (! $session->isCompleted()) {
                $service->complete($session, Auth::user());
            }
        }

        unset($this->sessions);
        Flux::toast(variant: 'success', text: __('Everyone’s session is logged.'));
    }

    public function reopen(int $workoutId, TrainerSession $service): void
    {
        $service->reopen($this->session($workoutId));
        unset($this->sessions);
    }

    /**
     * Clients who could join this session.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function addable(): Collection
    {
        $inSession = $this->sessions->pluck('client_id')->all();

        return Auth::user()->visibleClients()
            ->whereNotIn('id', $inSession)
            ->when(trim($this->addSearch) !== '', fn ($q) => $q->where('name', 'like', '%'.trim($this->addSearch).'%'))
            ->orderBy('name')
            ->limit(30)
            ->get();
    }

    public function addClient(int $clientId, TrainerSession $service): void
    {
        $client = Auth::user()->visibleClients()->whereKey($clientId)->first() ?? abort(404);
        $session = $service->start($client);

        if ($session === null) {
            Flux::toast(variant: 'warning', text: __('Nothing scheduled for :name. Build them a workout first.', ['name' => $client->name]));

            return;
        }

        $this->w = implode(',', [...$this->ids(), $session->id]);
        unset($this->sessions, $this->lastTimes, $this->addable);
        $this->loadLogs();
        $this->addSearch = '';

        Flux::modal('add-to-session')->close();
        $this->dispatch('session-added', id: $session->id);
    }

    public function removeFromSession(int $workoutId): void
    {
        $this->w = implode(',', array_values(array_diff($this->ids(), [$workoutId])));
        unset($this->sessions, $this->lastTimes, $this->addable, $this->logs[$workoutId], $this->notes[$workoutId]);

        if ($this->sessions->isEmpty()) {
            $this->redirectRoute('coach.train', navigate: true);
        }
    }
}; ?>

<div class="mx-auto flex w-full max-w-3xl flex-col gap-5 pb-32"
     x-data="{
        tab: {{ (int) ($this->sessions->first()?->id ?? 0) }},
        timers: {},
        ticker: null,
        startRest(id, name, seconds, label) {
            if (! seconds) return;
            this.timers[id] = { name, label, rest: seconds, total: seconds };
            if (! this.ticker) this.ticker = setInterval(() => this.tick(), 1000);
        },
        tick() {
            for (const id in this.timers) {
                if (--this.timers[id].rest <= 0) { delete this.timers[id]; if (navigator.vibrate) navigator.vibrate(300); }
            }
            if (! Object.keys(this.timers).length) { clearInterval(this.ticker); this.ticker = null; }
        },
        clock(t) { return Math.floor(t.rest / 60) + ':' + String(t.rest % 60).padStart(2, '0'); },
     }"
     x-on:session-added.window="tab = $event.detail.id">
    @php($sessions = $this->sessions)
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:breadcrumbs>
                <flux:breadcrumbs.item :href="route('coach.train')" wire:navigate>{{ __('Train') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ today()->translatedFormat('D j M') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>
            <flux:heading size="xl" level="1" class="mt-2">
                {{ $sessions->count() > 1 ? __('Group session') : __('Session with :name', ['name' => $sessions->first()?->client->name]) }}
            </flux:heading>
        </div>
        <flux:modal.trigger name="add-to-session">
            <flux:button size="sm" icon="user-plus" data-test="add-to-session">{{ __('Add client') }}</flux:button>
        </flux:modal.trigger>
    </div>

    @if ($sessions->count() > 1)
        <div class="sticky top-0 z-20 -mx-4 overflow-x-auto bg-white/95 px-4 py-2 backdrop-blur dark:bg-zinc-800/95" role="tablist">
            <div class="flex gap-2">
                @foreach ($sessions as $session)
                    @php($sets = collect($logs[$session->id] ?? [])->flatten(1))
                    <button type="button" role="tab" wire:key="tab-{{ $session->id }}"
                        x-on:click="tab = {{ $session->id }}"
                        :aria-selected="tab === {{ $session->id }}"
                        :class="tab === {{ $session->id }} ? 'border-brand bg-brand text-white dark:border-lime dark:bg-lime dark:text-ink' : 'border-zinc-300 text-zinc-700 dark:border-zinc-600 dark:text-zinc-200'"
                        class="flex shrink-0 items-center gap-2 rounded-full border px-3 py-1.5 text-sm font-semibold">
                        <span>{{ str($session->client->name)->before(' ') }}</span>
                        @if ($session->isCompleted())
                            <flux:icon.check-circle variant="micro" />
                        @else
                            <span class="tabular-nums opacity-75">{{ $sets->where('done', true)->count() }}/{{ $sets->count() }}</span>
                        @endif
                        {{-- Rest running for this client --}}
                        <span x-cloak x-show="timers[{{ $session->id }}]" class="size-2 rounded-full bg-amber-500" aria-hidden="true"></span>
                    </button>
                @endforeach
            </div>
        </div>
    @endif

    @foreach ($sessions as $session)
        <section wire:key="session-{{ $session->id }}" x-show="tab === {{ $session->id }} || {{ $sessions->count() === 1 ? 'true' : 'false' }}" class="flex flex-col gap-4" role="tabpanel">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-3">
                    <flux:avatar :name="$session->client->name" :initials="$session->client->initials()" />
                    <div>
                        <div class="font-display text-lg font-bold text-zinc-900 dark:text-white">{{ $session->title }}</div>
                        <flux:text size="sm">
                            {{ $session->client->name }}
                            @if ($session->week) · {{ __('Week :week', ['week' => $session->week]) }} @endif
                        </flux:text>
                    </div>
                </div>
                <div class="flex gap-1">
                    <flux:button size="sm" variant="ghost" icon="pencil-square" :href="route('coach.workouts.edit', $session)" wire:navigate>{{ __('Edit workout') }}</flux:button>
                    @if ($sessions->count() > 1)
                        <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeFromSession({{ $session->id }})" :aria-label="__('Take :name out of this session', ['name' => $session->client->name])" />
                    @endif
                </div>
            </div>

            @if ($session->client->goal || $session->client->coach_notes)
                <flux:callout icon="information-circle" class="text-sm">
                    @if ($session->client->goal)
                        <flux:callout.heading>{{ __('Goal: :goal', ['goal' => $session->client->goal]) }}</flux:callout.heading>
                    @endif
                    @if ($session->client->coach_notes)
                        <flux:callout.text class="whitespace-pre-line">{{ str($session->client->coach_notes)->limit(240) }}</flux:callout.text>
                    @endif
                </flux:callout>
            @endif

            @if ($session->isCompleted())
                <flux:callout icon="check-circle" color="green">
                    <flux:callout.heading>{{ __('Logged :when', ['when' => $session->completed_at->diffForHumans()]) }}</flux:callout.heading>
                    <x-slot name="actions">
                        <flux:button size="sm" wire:click="reopen({{ $session->id }})">{{ __('Reopen') }}</flux:button>
                    </x-slot>
                </flux:callout>
            @endif

            @forelse ($session->items as $index => $item)
                <flux:card class="flex flex-col gap-3" wire:key="item-{{ $item->id }}">
                    <div>
                        <div class="font-display text-lg font-bold text-zinc-900 dark:text-white">{{ $index + 1 }}. {{ $item->exercise->name }}</div>
                        <flux:text>{{ $item->prescription() }}</flux:text>
                        @if ($last = $this->lastTimes[$session->id][$item->exercise_id] ?? null)
                            <flux:text size="sm" class="mt-0.5 text-zinc-500">{{ __('Last time: :sets', ['sets' => $last]) }}</flux:text>
                        @endif
                        @if ($item->notes)
                            <flux:text size="sm" class="mt-1 italic">{{ $item->notes }}</flux:text>
                        @endif
                    </div>

                    <div class="flex flex-col gap-2">
                        <div class="grid grid-cols-[1.5rem_minmax(0,1fr)_minmax(0,1.3fr)_4.25rem_2.25rem] gap-2 text-xs font-medium uppercase text-zinc-500">
                            <span>{{ __('Set') }}</span><span>{{ __('Reps') }}</span><span>{{ __('Load') }}</span><span>RPE</span><span class="text-center">{{ __('Done') }}</span>
                        </div>
                        @foreach ($logs[$session->id][$item->id] ?? [] as $setIndex => $set)
                            <div class="grid grid-cols-[1.5rem_minmax(0,1fr)_minmax(0,1.3fr)_4.25rem_2.25rem] items-center gap-2" wire:key="set-{{ $item->id }}-{{ $setIndex }}">
                                <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">{{ $setIndex + 1 }}</span>
                                <flux:input size="sm" wire:model.live.debounce.600ms="logs.{{ $session->id }}.{{ $item->id }}.{{ $setIndex }}.reps" :placeholder="$item->reps" inputmode="numeric" :aria-label="__(':name, reps, set :n', ['name' => $session->client->name, 'n' => $setIndex + 1])" />
                                <flux:input size="sm" wire:model.live.debounce.600ms="logs.{{ $session->id }}.{{ $item->id }}.{{ $setIndex }}.load" :placeholder="$item->load" :aria-label="__(':name, load, set :n', ['name' => $session->client->name, 'n' => $setIndex + 1])" />
                                <flux:select size="sm" wire:model.live="logs.{{ $session->id }}.{{ $item->id }}.{{ $setIndex }}.rpe" :aria-label="__(':name, RPE, set :n', ['name' => $session->client->name, 'n' => $setIndex + 1])">
                                    <flux:select.option value="">—</flux:select.option>
                                    @foreach ($this::RPE_OPTIONS as $rpe)
                                        <flux:select.option :value="$rpe">{{ $rpe }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                <div class="flex justify-center">
                                    <flux:checkbox wire:model.live="logs.{{ $session->id }}.{{ $item->id }}.{{ $setIndex }}.done"
                                        :aria-label="__(':name, set :n done', ['name' => $session->client->name, 'n' => $setIndex + 1])"
                                        x-on:change="if ($event.target.checked) startRest({{ $session->id }}, @js(str($session->client->name)->before(' ')->toString()), {{ (int) $item->rest_seconds }}, @js($item->exercise->name))" />
                                </div>
                            </div>
                        @endforeach
                    </div>
                </flux:card>
            @empty
                <flux:card>
                    <flux:text>{{ __('No exercises in this workout yet.') }}</flux:text>
                    <flux:button size="sm" class="mt-3" icon="pencil-square" :href="route('coach.workouts.edit', $session)" wire:navigate>{{ __('Add exercises') }}</flux:button>
                </flux:card>
            @endforelse

            <flux:card class="flex flex-col gap-4">
                <flux:textarea wire:model.live.debounce.1000ms="notes.{{ $session->id }}" :label="__('Session notes')" :description="__(':name sees these with the session.', ['name' => str($session->client->name)->before(' ')])" :placeholder="__('Cues, how it moved, what to change next time.')" rows="3" />
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <flux:text size="sm" class="text-zinc-500">{{ __('Saved as you go.') }}</flux:text>
                    @unless ($session->isCompleted())
                        <flux:button variant="primary" icon="check" wire:click="complete({{ $session->id }})" data-test="complete-session-{{ $session->id }}">
                            {{ __('Finish :name’s session', ['name' => str($session->client->name)->before(' ')]) }}
                        </flux:button>
                    @endunless
                </div>
            </flux:card>
        </section>
    @endforeach

    @if ($sessions->count() > 1 && $sessions->contains(fn ($s) => ! $s->isCompleted()))
        <div class="flex justify-end">
            <flux:button icon="check-badge" wire:click="completeAll" wire:confirm="{{ __('Finish everyone’s session?') }}" data-test="complete-all">{{ __('Finish everyone') }}</flux:button>
        </div>
    @endif

    {{-- Rest timers, one per client, from each exercise's prescribed rest. --}}
    <div x-cloak x-show="Object.keys(timers).length" x-transition role="timer" aria-live="polite"
         class="fixed inset-x-0 bottom-0 z-40 border-t border-zinc-800 bg-ink px-4 py-3 text-white lg:start-64">
        <div class="mx-auto flex max-w-3xl flex-col gap-2">
            <template x-for="(t, id) in timers" :key="id">
                <div class="flex items-center gap-4">
                    <button type="button" class="flex min-w-0 flex-1 flex-col text-start" x-on:click="tab = Number(id)">
                        <span class="truncate text-xs uppercase tracking-wide text-zinc-400"><span x-text="t.name"></span> · <span x-text="t.label"></span></span>
                        <span class="font-display text-2xl font-extrabold tabular-nums text-lime" x-text="clock(t)"></span>
                    </button>
                    <div class="h-1.5 w-24 overflow-hidden rounded-full bg-zinc-700 sm:w-40">
                        <div class="h-full bg-lime transition-all duration-1000" :style="`width: ${t.total ? (t.rest / t.total) * 100 : 0}%`"></div>
                    </div>
                    <button type="button" class="rounded-lg border border-zinc-600 px-2.5 py-1.5 text-sm font-semibold" x-on:click="t.rest += 15; t.total += 15">+15s</button>
                    <button type="button" class="rounded-lg bg-lime px-2.5 py-1.5 text-sm font-bold text-ink" x-on:click="delete timers[id]">{{ __('Skip') }}</button>
                </div>
            </template>
        </div>
    </div>

    <flux:modal name="add-to-session" class="w-full md:w-[28rem]">
        <div class="flex flex-col gap-4">
            <div>
                <flux:heading size="lg">{{ __('Add a client') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Their session for today joins this one. If nothing’s on today, their next session moves to today.') }}</flux:text>
            </div>
            <flux:input wire:model.live.debounce.250ms="addSearch" icon="magnifying-glass" :placeholder="__('Search clients')" />
            <div class="flex max-h-80 flex-col gap-1 overflow-y-auto">
                @forelse ($this->addable as $client)
                    <button type="button" wire:key="add-{{ $client->id }}" wire:click="addClient({{ $client->id }})" class="flex items-center gap-3 rounded-lg px-2 py-2 text-start hover:bg-zinc-50 dark:hover:bg-zinc-700">
                        <flux:avatar size="sm" :name="$client->name" :initials="$client->initials()" />
                        <span class="flex-1 font-medium text-zinc-900 dark:text-white">{{ $client->name }}</span>
                        @if ($client->isInPersonOnly())
                            <flux:badge size="sm" color="zinc">{{ __('In person') }}</flux:badge>
                        @endif
                    </button>
                @empty
                    <flux:text>{{ __('No other clients to add.') }}</flux:text>
                @endforelse
            </div>
        </div>
    </flux:modal>
</div>
