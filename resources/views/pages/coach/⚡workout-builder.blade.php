<?php

use App\Models\Exercise;
use App\Models\Workout;
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
            ])
            ->all();
    }

    #[Computed]
    public function exerciseResults()
    {
        return Exercise::query()
            ->availableTo(Auth::user())
            ->when($this->exerciseSearch, fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->exerciseSearch}%")
                ->orWhere('muscle_group', 'like', "%{$this->exerciseSearch}%")))
            ->orderBy('name')
            ->limit(12)
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

        $this->workout->update([
            'title' => $this->title,
            'instructions' => $this->instructions ?: null,
            'scheduled_on' => $this->workout->isTemplate() ? null : $this->scheduledOn,
        ]);

        foreach ($this->items as $position => $item) {
            $this->workout->items()->whereKey($item['id'])->update([
                'position' => $position,
                'sets' => (int) $item['sets'],
                'reps' => $item['reps'] ?: null,
                'load' => $item['load'] ?: null,
                'rest_seconds' => $item['rest_seconds'] === '' ? null : (int) $item['rest_seconds'],
                'notes' => $item['notes'] ?: null,
            ]);
        }

        if ($notify) {
            Flux::toast(variant: 'success', text: __('Workout saved.'));
        }
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

        $this->loadItems();
        Flux::modal('exercise-picker')->close();
        $this->exerciseSearch = '';
    }

    public function removeItem(int $index): void
    {
        $this->save(notify: false);

        $this->workout->items()->whereKey($this->items[$index]['id'] ?? 0)->delete();
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

        $template = $this->workout->replicate(['client_id', 'scheduled_on', 'completed_at', 'client_feedback', 'template_id', 'program_id', 'week', 'day'])->unsetRelations();
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

    @if ($workout->isCompleted())
        <flux:callout icon="check-circle" color="green">
            <flux:callout.heading>{{ __('Completed :when', ['when' => $workout->completed_at->diffForHumans()]) }}</flux:callout.heading>
            @if ($workout->client_feedback)
                <flux:callout.text>“{{ $workout->client_feedback }}”</flux:callout.text>
            @endif
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

                @if (! empty($item['logged_sets']))
                    <div class="rounded-lg bg-zinc-50 p-3 text-sm dark:bg-zinc-900">
                        <div class="mb-1 font-medium text-zinc-700 dark:text-zinc-300">{{ __('Client logged') }}</div>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($item['logged_sets'] as $i => $set)
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
                        <span class="font-medium text-zinc-900 dark:text-white">{{ $exercise->name }}</span>
                        <span class="text-sm text-zinc-500">{{ $exercise->muscle_group }}</span>
                    </button>
                @empty
                    <flux:text class="py-4">{{ __('No matches. You can add custom exercises in your library.') }}</flux:text>
                @endforelse
            </div>
        </div>
    </flux:modal>
</div>
