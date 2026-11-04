<?php

use App\Models\Exercise;
use App\Models\Workout;
use App\Support\Restrictions;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Workout builder')] class extends Component {
    public Workout $workout;

    public string $title = '';

    public string $instructions = '';

    public string $scheduledOn = '';

    /** @var array<int, array<string, mixed>> */
    public array $items = [];

    public string $exerciseSearch = '';

    /**
     * Re-checked on every action: access can change while the page is open (reassignment, removal).
     */
    public function hydrate(): void
    {
        Gate::authorize('update', $this->workout);
    }

    public function mount(Workout $workout): void
    {
        Gate::authorize('update', $workout);

        $this->workout = $workout;
        $this->title = $workout->title;
        $this->instructions = (string) $workout->instructions;
        $this->scheduledOn = (string) $workout->scheduled_on?->toDateString();
        $this->loadItems();
    }

    protected function loadItems(): void
    {
        $this->items = $this->workout->items()->with('exercise')->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->exercise->name,
                'muscle_group' => $item->exercise->muscle_group,
                'sets' => $item->sets,
                'reps' => (string) $item->reps,
                'load' => (string) $item->load,
                'rest_seconds' => (string) $item->rest_seconds,
                'notes' => (string) $item->notes,
                'logged_sets' => $item->logged_sets,
                'client_note' => $item->client_note,
                'pain' => $item->pain,
                'conflicts' => Restrictions::conflictsFor($item->exercise, $this->restrictions)->map(fn ($r) => Restrictions::describe($r))->all(),
            ])
            ->all();
    }

    /**
     * The client's injury restrictions (none for templates).
     */
    #[Computed]
    public function restrictions()
    {
        return Restrictions::forClient($this->workout->client);
    }

    #[Computed]
    public function exerciseResults()
    {
        return Exercise::query()
            ->availableTo(Auth::user())
            ->when($this->exerciseSearch, fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->exerciseSearch}%")
                ->orWhere('muscle_group', 'like', "%{$this->exerciseSearch}%")))
            ->orderByRaw('case when name like ? then 0 else 1 end', [$this->exerciseSearch.'%'])
            // Your team's exercises, then Spotter's starter list, then the wider open library.
            ->orderByRaw("case when coach_id is not null then 0 when source = 'free-exercise-db' then 2 else 1 end")
            ->orderBy('name')
            ->limit(20)
            ->get();
    }

    public function save(bool $notify = true): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:120'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'scheduledOn' => [$this->workout->isTemplate() ? 'nullable' : 'required', 'date'],
            'items.*.sets' => ['required', 'integer', 'min:1', 'max:20'],
            'items.*.reps' => ['nullable', 'string', 'max:30'],
            'items.*.load' => ['nullable', 'string', 'max:30'],
            'items.*.rest_seconds' => ['nullable', 'integer', 'min:0', 'max:3600'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ], attributes: [
            'items.*.sets' => 'sets',
            'items.*.rest_seconds' => 'rest',
        ]);

        $this->workout->fill([
            'title' => $this->title,
            'instructions' => $this->instructions ?: null,
            'scheduled_on' => $this->workout->isTemplate() ? null : $this->scheduledOn,
        ]);
        $changed = $this->workout->isDirty();
        $this->workout->save();

        $existing = $this->workout->items()->get()->keyBy('id');

        foreach ($this->items as $position => $item) {
            $row = $existing->get($item['id']);

            if (! $row) {
                continue;
            }

            $row->fill([
                'position' => $position,
                'sets' => (int) $item['sets'],
                'reps' => $item['reps'] ?: null,
                'load' => $item['load'] ?: null,
                'rest_seconds' => $item['rest_seconds'] === '' ? null : (int) $item['rest_seconds'],
                'notes' => $item['notes'] ?: null,
            ]);
            $changed = $changed || $row->isDirty();
            $row->save();
        }

        // A program session edited by hand stops following program edits.
        if ($changed) {
            $this->markEdited();
        }

        if ($notify) {
            Flux::toast(variant: 'success', text: __('Workout saved.'));
        }
    }

    protected function markEdited(): void
    {
        app(\App\Services\ProgramScheduler::class)->customize($this->workout);
    }

    /**
     * Put a hand-edited program session back on the program.
     */
    public function resetToProgram(): void
    {
        app(\App\Services\ProgramScheduler::class)->reset($this->workout);
        $this->workout->refresh();
        $this->title = $this->workout->title;
        $this->instructions = (string) $this->workout->instructions;
        $this->scheduledOn = (string) $this->workout->scheduled_on?->toDateString();
        $this->loadItems();

        Flux::toast(variant: 'success', text: __('Back on the program.'));
    }

    public function addExercise(int $exerciseId): void
    {
        $exercise = Exercise::query()->availableTo(Auth::user())->findOrFail($exerciseId);

        $this->save(notify: false);

        $this->workout->items()->create([
            'exercise_id' => $exercise->id,
            'position' => count($this->items),
            'sets' => 3,
            'reps' => '10',
        ]);

        $this->markEdited();
        $this->loadItems();
        Flux::modal('exercise-picker')->close();
        $this->exerciseSearch = '';
    }

    public function removeItem(int $index): void
    {
        $this->save(notify: false);

        $this->workout->items()->whereKey($this->items[$index]['id'] ?? 0)->delete();
        $this->markEdited();
        unset($this->items[$index]);
        $this->items = array_values($this->items);

        $this->save(notify: false);
    }

    public function move(int $index, int $direction): void
    {
        $target = $index + $direction;

        if (! isset($this->items[$index], $this->items[$target])) {
            return;
        }

        [$this->items[$index], $this->items[$target]] = [$this->items[$target], $this->items[$index]];

        $this->save(notify: false);
    }

    public function saveAsTemplate(): void
    {
        $this->save(notify: false);

        $template = $this->workout->replicate(['client_id', 'scheduled_on', 'moved_from', 'completed_at', 'client_feedback', 'reported_at', 'template_id', 'program_id', 'week', 'day', 'program_day_id', 'customized_at'])->unsetRelations();
        $template->client_id = null;
        $template->save();

        foreach ($this->workout->items as $item) {
            $template->items()->create($item->only(\App\Models\WorkoutExercise::PRESCRIPTION_FIELDS));
        }

        Flux::toast(variant: 'success', text: __('Saved to your templates.'));
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            @if ($workout->isTemplate())
                <flux:breadcrumbs>
                    <flux:breadcrumbs.item :href="route('coach.templates')" wire:navigate>{{ __('Templates') }}</flux:breadcrumbs.item>
                    <flux:breadcrumbs.item>{{ $workout->title }}</flux:breadcrumbs.item>
                </flux:breadcrumbs>
            @else
                <flux:breadcrumbs>
                    <flux:breadcrumbs.item :href="route('coach.clients.index')" wire:navigate>{{ __('Clients') }}</flux:breadcrumbs.item>
                    <flux:breadcrumbs.item :href="route('coach.clients.show', $workout->client_id)" wire:navigate>{{ $workout->client->name }}</flux:breadcrumbs.item>
                    <flux:breadcrumbs.item>{{ $workout->title }}</flux:breadcrumbs.item>
                </flux:breadcrumbs>
            @endif
        </div>

        <div class="flex gap-2">
            @unless ($workout->isTemplate())
                <flux:button icon="document-duplicate" wire:click="saveAsTemplate">{{ __('Save as template') }}</flux:button>
            @endunless
            <flux:button variant="primary" wire:click="save" data-test="save-workout-button">{{ __('Save') }}</flux:button>
        </div>
    </div>

    @if ($workout->program_day_id && ! $workout->isCompleted())
        @if ($workout->customized_at)
            <flux:callout icon="pencil-square" color="amber">
                <flux:callout.heading>{{ __('Edited by hand') }}</flux:callout.heading>
                <flux:callout.text>{{ __('Changes to the program skip this session. Week :week of :program.', ['week' => $workout->week, 'program' => $workout->program?->title]) }}</flux:callout.text>
                <x-slot name="actions">
                    @unless ($workout->isStarted())
                        <flux:button size="sm" wire:click="resetToProgram" wire:confirm="{{ __('Undo your changes to this session and follow the program again?') }}">{{ __('Reset to program') }}</flux:button>
                    @endunless
                    <flux:button size="sm" variant="ghost" :href="route('coach.programs.show', $workout->program_id)" wire:navigate>{{ __('Open program') }}</flux:button>
                </x-slot>
            </flux:callout>
        @else
            <flux:callout icon="calendar-days">
                <flux:callout.heading>{{ __('Week :week of :program', ['week' => $workout->week, 'program' => $workout->program?->title]) }}</flux:callout.heading>
                <flux:callout.text>{{ __('Changes here apply to this session only. To change every week, edit the program.') }}</flux:callout.text>
                <x-slot name="actions">
                    <flux:button size="sm" :href="route('coach.programs.show', $workout->program_id)" wire:navigate>{{ __('Edit program') }}</flux:button>
                </x-slot>
            </flux:callout>
        @endif
    @endif

    @if ($workout->isCompleted())
        <flux:callout icon="check-circle" color="green">
            <flux:callout.heading>{{ __('Completed :when', ['when' => $workout->completed_at->diffForHumans()]) }}</flux:callout.heading>
            @if ($workout->client_feedback)
                <flux:callout.text>“{{ $workout->client_feedback }}”</flux:callout.text>
            @endif
        </flux:callout>
    @endif

    @if ($this->restrictions->isNotEmpty())
        <flux:callout icon="exclamation-triangle" color="amber" data-test="client-restrictions">
            <flux:callout.heading>{{ __(':name’s restrictions', ['name' => str($workout->client->name)->before(' ')]) }}</flux:callout.heading>
            <flux:callout.text>
                {{ $this->restrictions->map(fn ($r) => \App\Support\Restrictions::describe($r).($r['note'] ? ' ('.$r['note'].')' : ''))->implode(' · ') }}
            </flux:callout.text>
        </flux:callout>
    @endif

    <flux:card class="flex flex-col gap-4">
        <div class="flex flex-col gap-4 md:flex-row">
            <div class="md:flex-1">
                <flux:input wire:model="title" :label="__('Title')" required />
            </div>
            @unless ($workout->isTemplate())
                <div class="md:w-48">
                    <flux:input wire:model="scheduledOn" type="date" :label="__('Scheduled for')" />
                </div>
            @endunless
        </div>
        <flux:textarea wire:model="instructions" :label="__('Instructions for the client')" rows="2" :placeholder="__('Warm-up, tempo, how hard to push…')" />
    </flux:card>

    <div class="flex flex-col gap-3">
        @foreach ($items as $index => $item)
            <flux:card class="flex flex-col gap-4" wire:key="item-{{ $item['id'] }}">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <span class="flex size-7 items-center justify-center rounded-full bg-zinc-100 text-sm font-semibold text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200">{{ $index + 1 }}</span>
                        <div>
                            <div class="font-semibold text-zinc-900 dark:text-white">{{ $item['name'] }}</div>
                            @if ($item['muscle_group'])
                                <flux:text size="sm">{{ $item['muscle_group'] }}</flux:text>
                            @endif
                            @foreach ($item['conflicts'] ?? [] as $conflict)
                                <flux:badge size="sm" color="amber" icon="exclamation-triangle" class="mt-1" data-test="restriction-conflict">{{ $conflict }}</flux:badge>
                            @endforeach
                        </div>
                    </div>
                    <div class="flex gap-1">
                        <flux:button size="sm" variant="ghost" icon="chevron-up" wire:click="move({{ $index }}, -1)" :disabled="$index === 0" />
                        <flux:button size="sm" variant="ghost" icon="chevron-down" wire:click="move({{ $index }}, 1)" :disabled="$index === count($items) - 1" />
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeItem({{ $index }})" />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <flux:input wire:model="items.{{ $index }}.sets" type="number" min="1" max="20" :label="__('Sets')" size="sm" />
                    <flux:input wire:model="items.{{ $index }}.reps" :label="__('Reps')" placeholder="8-10" size="sm" />
                    <flux:input wire:model="items.{{ $index }}.load" :label="__('Load')" placeholder="135 lb / RPE 8" size="sm" />
                    <flux:input wire:model="items.{{ $index }}.rest_seconds" type="number" min="0" :label="__('Rest (sec)')" size="sm" />
                </div>
                <flux:input wire:model="items.{{ $index }}.notes" :placeholder="__('Notes for this exercise (optional)')" size="sm" />

                @if (! empty($item['logged_sets']) || ! empty($item['client_note']) || ! empty($item['pain']))
                    <div class="rounded-lg bg-zinc-50 p-3 text-sm dark:bg-zinc-900">
                        <div class="mb-1 flex items-center gap-2 font-medium text-zinc-700 dark:text-zinc-300">
                            {{ __('Client logged') }}
                            @if (! empty($item['pain']))
                                <flux:badge size="sm" color="red">{{ __('Pain flagged') }}</flux:badge>
                            @endif
                        </div>
                        @if (! empty($item['client_note']))
                            <p class="mb-2 italic text-zinc-700 dark:text-zinc-300">“{{ $item['client_note'] }}”</p>
                        @endif
                        <div class="flex flex-wrap gap-2">
                            @foreach ($item['logged_sets'] ?? [] as $i => $set)
                                <flux:badge size="sm" :color="($set['done'] ?? false) ? 'green' : 'zinc'">
                                    {{ __('Set :n', ['n' => $i + 1]) }}: {{ $set['reps'] ?: '—' }}{{ ($set['load'] ?? null) ? ' @ '.$set['load'] : '' }}
                                </flux:badge>
                            @endforeach
                        </div>
                    </div>
                @endif
            </flux:card>
        @endforeach

        @if (empty($items))
            <flux:card class="py-10 text-center">
                <flux:text>{{ __('No exercises yet. Add the first one below.') }}</flux:text>
            </flux:card>
        @endif

        <flux:modal.trigger name="exercise-picker">
            <flux:button icon="plus" class="self-start" data-test="add-exercise-button">{{ __('Add exercise') }}</flux:button>
        </flux:modal.trigger>
    </div>

    <flux:modal name="exercise-picker" class="w-full md:w-[32rem]">
        <div class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Add an exercise') }}</flux:heading>
            <flux:input wire:model.live.debounce.250ms="exerciseSearch" icon="magnifying-glass" :placeholder="__('Search by name or muscle group')" autofocus />
            <div class="flex max-h-96 flex-col divide-y divide-zinc-100 overflow-y-auto dark:divide-zinc-700">
                @forelse ($this->exerciseResults as $exercise)
                    <button type="button" wire:click="addExercise({{ $exercise->id }})" wire:key="pick-{{ $exercise->id }}" class="flex items-center justify-between gap-3 px-1 py-2 text-start hover:bg-zinc-50 dark:hover:bg-zinc-700/50">
                        <span class="flex min-w-0 items-center gap-3">
                            @if ($thumb = $exercise->imageUrls()[0] ?? null)
                                <img src="{{ $thumb }}" alt="" loading="lazy" class="size-9 shrink-0 rounded bg-zinc-100 object-cover">
                            @else
                                <span class="size-9 shrink-0 rounded bg-zinc-100 dark:bg-zinc-800"></span>
                            @endif
                            <span class="min-w-0">
                                <span class="block truncate font-medium text-zinc-900 dark:text-white">{{ $exercise->name }}</span>
                                @foreach (\App\Support\Restrictions::conflictsFor($exercise, $this->restrictions) as $conflict)
                                    <span class="block text-xs font-medium text-amber-700 dark:text-amber-400">⚠ {{ \App\Support\Restrictions::describe($conflict) }}</span>
                                @endforeach
                            </span>
                        </span>
                        <span class="shrink-0 text-sm text-zinc-500">{{ collect([$exercise->muscle_group, $exercise->equipment])->filter()->implode(' · ') }}</span>
                    </button>
                @empty
                    <flux:text class="py-4">{{ __('No matches. You can add custom exercises in your library.') }}</flux:text>
                @endforelse
            </div>
        </div>
    </flux:modal>
</div>
