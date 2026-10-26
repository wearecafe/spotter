<?php

use App\Models\Exercise;
use App\Models\Program;
use App\Models\Workout;
use App\Models\WorkoutExercise;
use App\Services\Progression;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Program')] class extends Component {
    public Program $program;

    #[Url]
    public int $week = 1;

    /** @var array<int, array<string, string>> editable cells keyed by workout_exercise id */
    public array $rows = [];

    public ?int $historyFor = null;

    public ?int $addingToWorkout = null;

    public string $exerciseSearch = '';

    public const EDITABLE = ['sets', 'reps', 'load', 'target_rpe', 'rest_seconds', 'progression', 'progression_step'];

    public function mount(Program $program): void
    {
        Gate::authorize('manage-program', $program);

        $this->program = $program->load('client');
        $this->clampWeek();
        $this->loadRows();
    }

    protected function clampWeek(): void
    {
        $this->week = min(max($this->week, 1), max($this->program->lastWeek(), 1));
    }

    public function updatedWeek(): void
    {
        $this->clampWeek();
        $this->loadRows();
    }

    #[Computed]
    public function weeks(): array
    {
        return range(1, max($this->program->lastWeek(), 1));
    }

    #[Computed]
    public function days()
    {
        return $this->program->workouts()->where('week', $this->week)->with('items.exercise')->get();
    }

    protected function loadRows(): void
    {
        unset($this->days);
        $this->rows = [];

        foreach ($this->days as $day) {
            foreach ($day->items as $item) {
                $this->rows[$item->id] = collect(self::EDITABLE)->mapWithKeys(fn ($f) => [$f => (string) $item->{$f}])->all();
            }
        }
    }

    public function selectWeek(int $week): void
    {
        $this->week = $week;
        $this->clampWeek();
        $this->historyFor = null;
        $this->loadRows();
    }

    /**
     * Save a cell as soon as it changes, like a spreadsheet.
     */
    public function updatedRows(mixed $value, string $key): void
    {
        [$id, $field] = explode('.', $key, 2) + [null, null];

        if (! in_array($field, self::EDITABLE, true)) {
            return;
        }

        $item = $this->ownedItem((int) $id);

        $rules = [
            'sets' => ['required', 'integer', 'min:1', 'max:20'],
            'reps' => ['nullable', 'string', 'max:30'],
            'load' => ['nullable', 'string', 'max:30'],
            'target_rpe' => ['nullable', 'string', 'max:10'],
            'rest_seconds' => ['nullable', 'integer', 'min:0', 'max:3600'],
            'progression' => ['required', 'in:'.implode(',', array_keys(WorkoutExercise::PROGRESSIONS))],
            'progression_step' => ['nullable', 'string', 'max:20'],
        ];

        $this->validate(["rows.$id.$field" => $rules[$field]], attributes: ["rows.$id.$field" => __(str_replace('_', ' ', $field))]);

        $item->update([$field => $value === '' ? null : $value]);
    }

    protected function ownedItem(int $id): WorkoutExercise
    {
        return WorkoutExercise::query()
            ->whereKey($id)
            ->whereHas('workout', fn ($q) => $q->where('program_id', $this->program->id))
            ->firstOrFail();
    }

    public function generateNextWeek(Progression $progression): void
    {
        $this->week = $progression->generateNextWeek($this->program);
        unset($this->weeks);
        $this->loadRows();

        Flux::toast(variant: 'success', text: __('Week :week is ready. Highlighted cells were changed by your rules.', ['week' => $this->week]));
    }

    public function showHistory(int $id): void
    {
        $this->historyFor = $this->historyFor === $id ? null : $id;
    }

    /**
     * Planned vs actual for the selected exercise across every week of the program.
     */
    #[Computed]
    public function history()
    {
        if (! $this->historyFor) {
            return collect();
        }

        $item = WorkoutExercise::with('exercise')->find($this->historyFor);

        if (! $item) {
            return collect();
        }

        return WorkoutExercise::query()
            ->where('exercise_id', $item->exercise_id)
            ->whereHas('workout', fn ($q) => $q->where('program_id', $this->program->id))
            ->with('workout:id,week,title,completed_at')
            ->get()
            ->sortBy(fn ($i) => $i->workout->week)
            ->values();
    }

    public function addDay(): void
    {
        $this->clampWeek();
        $day = (int) $this->program->workouts()->where('week', $this->week)->max('day') + 1;
        $previous = $this->program->workouts()->where('week', $this->week)->orderByDesc('day')->first();

        Workout::create([
            'coach_id' => Auth::id(),
            'client_id' => $this->program->client_id,
            'program_id' => $this->program->id,
            'week' => $this->week,
            'day' => $day,
            'title' => __('Day :label', ['label' => chr(64 + min($day, 26))]),
            'scheduled_on' => $previous?->scheduled_on?->addDays(2) ?? $this->program->starts_on->addWeeks($this->week - 1),
        ]);

        $this->loadRows();
    }

    public function openPicker(int $workoutId): void
    {
        $this->addingToWorkout = $workoutId;
        $this->exerciseSearch = '';
        Flux::modal('program-exercise-picker')->show();
    }

    #[Computed]
    public function exerciseResults()
    {
        return Exercise::query()
            ->availableTo(Auth::user())
            ->when($this->exerciseSearch, fn ($q) => $q->where('name', 'like', "%{$this->exerciseSearch}%"))
            ->orderBy('name')
            ->limit(12)
            ->get();
    }

    public function addExercise(int $exerciseId): void
    {
        $workout = $this->program->workouts()->findOrFail($this->addingToWorkout);
        $exercise = Exercise::query()->availableTo(Auth::user())->findOrFail($exerciseId);

        $workout->items()->create([
            'exercise_id' => $exercise->id,
            'position' => $workout->items()->count(),
            'sets' => 3,
            'reps' => '8',
            'progression' => 'load',
        ]);

        Flux::modal('program-exercise-picker')->close();
        $this->loadRows();
    }

    public function removeItem(int $id): void
    {
        $this->ownedItem($id)->delete();

        if ($this->historyFor === $id) {
            $this->historyFor = null;
        }

        $this->loadRows();
    }
}; ?>

<div class="flex flex-col gap-5">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:breadcrumbs>
                <flux:breadcrumbs.item :href="route('coach.clients.index')" wire:navigate>{{ __('Clients') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item :href="route('coach.clients.show', $program->client)" wire:navigate>{{ $program->client->name }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ $program->title }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>
            <flux:heading size="xl" level="1" class="mt-2">{{ $program->title }}</flux:heading>
            <flux:text class="mt-1">{{ __('Week :week of :planned · started :date', ['week' => $week, 'planned' => $program->planned_weeks, 'date' => $program->starts_on->translatedFormat('j M')]) }}</flux:text>
        </div>
        <flux:button variant="primary" icon="sparkles" wire:click="generateNextWeek" data-test="generate-week">
            {{ __('Generate week :n', ['n' => count($this->weeks) + 1]) }}
        </flux:button>
    </div>

    <div role="tablist" aria-label="{{ __('Weeks') }}" class="flex flex-wrap gap-1 self-start rounded-xl bg-zinc-100 p-1 dark:bg-zinc-800">
        @foreach ($this->weeks as $w)
            <button type="button" role="tab" aria-selected="{{ $w === $week ? 'true' : 'false' }}" wire:click="selectWeek({{ $w }})"
                @class(['h-9 rounded-lg px-4 text-sm font-semibold transition', 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-white' => $w === $week, 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400' => $w !== $week])>
                {{ __('Week :n', ['n' => $w]) }}
            </button>
        @endforeach
    </div>

    <div class="grid gap-5 2xl:grid-cols-[minmax(0,1fr)_20rem]">
        <div class="flex min-w-0 flex-col gap-5">
            @forelse ($this->days as $day)
                <flux:card class="flex min-w-0 flex-col gap-3 p-0!" wire:key="day-{{ $day->id }}">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                        <div class="flex items-center gap-3">
                            <a href="{{ route('coach.workouts.edit', $day) }}" wire:navigate class="font-display font-bold text-zinc-900 hover:underline dark:text-white">{{ $day->title }}</a>
                            <flux:text size="sm">{{ $day->scheduled_on?->translatedFormat('D j M') }}</flux:text>
                            @if ($day->isCompleted())
                                <flux:badge size="sm" color="green" icon="check">{{ __('Done') }}</flux:badge>
                            @endif
                        </div>
                        <flux:button size="sm" variant="ghost" icon="plus" wire:click="openPicker({{ $day->id }})">{{ __('Exercise') }}</flux:button>
                    </div>

                    <div class="overflow-x-auto px-2 pb-2">
                        <table class="w-full min-w-[52rem] text-sm">
                            <thead>
                                <tr class="text-xs uppercase tracking-wide text-zinc-500">
                                    <th scope="col" class="px-2 py-1 text-start font-medium">{{ __('Exercise') }}</th>
                                    <th scope="col" class="w-16 px-1 py-1 text-start font-medium">{{ __('Sets') }}</th>
                                    <th scope="col" class="w-20 px-1 py-1 text-start font-medium">{{ __('Reps') }}</th>
                                    <th scope="col" class="w-24 px-1 py-1 text-start font-medium">{{ __('Load') }}</th>
                                    <th scope="col" class="w-16 px-1 py-1 text-start font-medium">RPE</th>
                                    <th scope="col" class="w-20 px-1 py-1 text-start font-medium">{{ __('Rest s') }}</th>
                                    <th scope="col" class="w-32 px-1 py-1 text-start font-medium">{{ __('Rule') }}</th>
                                    <th scope="col" class="w-20 px-1 py-1 text-start font-medium">{{ __('Step') }}</th>
                                    <th scope="col" class="w-20 px-1 py-1"><span class="sr-only">{{ __('Actions') }}</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($day->items as $item)
                                    <tr wire:key="row-{{ $item->id }}" @class(['border-t border-zinc-100 dark:border-zinc-700', 'bg-zinc-50 dark:bg-zinc-800/60' => $historyFor === $item->id])>
                                        <td class="px-2 py-1.5">
                                            <button type="button" wire:click="showHistory({{ $item->id }})" class="text-start font-medium text-zinc-900 hover:underline dark:text-white">{{ $item->exercise->name }}</button>
                                            @if ($item->change_reason)
                                                <div class="text-xs text-zinc-500">{{ $item->change_reason }}</div>
                                            @endif
                                            @if ($item->logged_sets)
                                                <div class="text-xs tabular-nums text-brand dark:text-lime">
                                                    {{ __('Did: :sets', ['sets' => collect($item->logged_sets)->filter(fn ($s) => $s['done'] ?? false)->map(fn ($s) => ($s['reps'] ?? '—').(($s['load'] ?? null) ? '@'.$s['load'] : ''))->implode(', ') ?: '—']) }}
                                                </div>
                                            @endif
                                        </td>
                                        @foreach (['sets' => 'number', 'reps' => 'text', 'load' => 'text', 'target_rpe' => 'text', 'rest_seconds' => 'number'] as $field => $inputType)
                                            <td class="px-1 py-1.5">
                                                <input type="{{ $inputType }}" wire:model.blur="rows.{{ $item->id }}.{{ $field }}"
                                                    aria-label="{{ __(':field for :exercise', ['field' => str_replace('_', ' ', $field), 'exercise' => $item->exercise->name]) }}"
                                                    title="{{ $item->progressionChanged($field) ? $item->change_reason : '' }}"
                                                    @class(['h-9 w-full rounded-md border border-zinc-200 bg-white px-2 tabular-nums dark:border-zinc-600 dark:bg-zinc-900', 'cell-changed font-semibold' => $item->progressionChanged($field)])>
                                            </td>
                                        @endforeach
                                        <td class="px-1 py-1.5">
                                            <select wire:model.live="rows.{{ $item->id }}.progression" aria-label="{{ __('Progression rule for :exercise', ['exercise' => $item->exercise->name]) }}" class="h-9 w-full rounded-md border border-zinc-200 bg-white px-2 dark:border-zinc-600 dark:bg-zinc-900">
                                                @foreach (\App\Models\WorkoutExercise::PROGRESSIONS as $key => $label)
                                                    <option value="{{ $key }}">{{ __($label) }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="px-1 py-1.5">
                                            <input type="text" wire:model.blur="rows.{{ $item->id }}.progression_step" placeholder="{{ ($rows[$item->id]['progression'] ?? '') === 'reps' ? '1' : \App\Support\Load::defaultStep($item->load) }}"
                                                aria-label="{{ __('Step for :exercise', ['exercise' => $item->exercise->name]) }}"
                                                @disabled(in_array($rows[$item->id]['progression'] ?? 'none', ['none', 'hold'], true))
                                                class="h-9 w-full rounded-md border border-zinc-200 bg-white px-2 disabled:opacity-40 dark:border-zinc-600 dark:bg-zinc-900">
                                        </td>
                                        <td class="px-1 py-1.5 text-end">
                                            <flux:button size="xs" variant="ghost" icon="chart-bar" wire:click="showHistory({{ $item->id }})" :aria-label="__('History')" />
                                            <flux:button size="xs" variant="ghost" icon="trash" wire:click="removeItem({{ $item->id }})" wire:confirm="{{ __('Remove :exercise from this day?', ['exercise' => $item->exercise->name]) }}" :aria-label="__('Remove')" />
                                        </td>
                                    </tr>
                                    @foreach ($this::EDITABLE as $field)
                                        @error("rows.{$item->id}.{$field}")
                                            <tr><td colspan="9" class="px-2 pb-2 text-sm text-red-600">{{ $message }}</td></tr>
                                        @enderror
                                    @endforeach
                                @endforeach
                            </tbody>
                        </table>
                        @if ($day->items->isEmpty())
                            <flux:text class="px-2 py-4">{{ __('No exercises yet.') }}</flux:text>
                        @endif
                    </div>
                </flux:card>
            @empty
                <flux:card><flux:text>{{ __('This week has no days yet.') }}</flux:text></flux:card>
            @endforelse

            <flux:button icon="plus" class="self-start" wire:click="addDay">{{ __('Add a day to week :n', ['n' => $week]) }}</flux:button>
        </div>

        <aside class="flex flex-col gap-4">
            <flux:card class="flex flex-col gap-3">
                <flux:heading>{{ __('How rules work') }}</flux:heading>
                <flux:text size="sm">{{ __('When you generate the next week, each row checks what :name actually did. If every set was done, reps hit the target and RPE stayed at or under target, the rule applies. Otherwise the row holds.', ['name' => str($program->client->name)->before(' ')]) }}</flux:text>
                <ul class="flex flex-col gap-1 text-sm text-zinc-700 dark:text-zinc-300">
                    <li><strong>{{ __('Add load') }}</strong>: {{ __('adds the step, e.g. 2.5 kg or 5%') }}</li>
                    <li><strong>{{ __('Add reps') }}</strong>: {{ __('climbs a rep range, e.g. 8-10 to 9-10') }}</li>
                    <li><strong>{{ __('Hold') }}</strong>: {{ __('repeats the row unchanged') }}</li>
                </ul>
            </flux:card>

            @if ($this->history->isNotEmpty())
                <flux:card class="flex flex-col gap-3">
                    <flux:heading>{{ $this->history->first()->exercise->name }}</flux:heading>
                    <table class="w-full text-sm">
                        <thead><tr class="text-xs uppercase text-zinc-500"><th class="py-1 text-start font-medium">{{ __('Wk') }}</th><th class="py-1 text-start font-medium">{{ __('Planned') }}</th><th class="py-1 text-start font-medium">{{ __('Actual') }}</th></tr></thead>
                        <tbody>
                            @foreach ($this->history as $h)
                                <tr class="border-t border-zinc-100 align-top dark:border-zinc-700">
                                    <td class="py-1.5 pe-2 tabular-nums">{{ $h->workout->week }}</td>
                                    <td class="py-1.5 pe-2">{{ $h->sets }} × {{ $h->reps ?: '—' }}{{ $h->load ? ' @ '.$h->load : '' }}</td>
                                    <td class="py-1.5 tabular-nums text-zinc-600 dark:text-zinc-300">
                                        {{ collect($h->logged_sets ?? [])->filter(fn ($s) => $s['done'] ?? false)->map(fn ($s) => ($s['reps'] ?? '—').(($s['load'] ?? null) ? '@'.$s['load'] : '').(($s['rpe'] ?? null) ? ' ('.$s['rpe'].')' : ''))->implode(', ') ?: ($h->workout->isCompleted() ? '—' : __('not yet')) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </flux:card>
            @else
                <flux:text size="sm" class="px-1">{{ __('Click an exercise to see planned vs actual across the program.') }}</flux:text>
            @endif
        </aside>
    </div>

    <flux:modal name="program-exercise-picker" class="w-full md:w-[32rem]">
        <div class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Add an exercise') }}</flux:heading>
            <flux:input wire:model.live.debounce.250ms="exerciseSearch" icon="magnifying-glass" :placeholder="__('Search exercises')" autofocus />
            <div class="flex max-h-96 flex-col divide-y divide-zinc-100 overflow-y-auto dark:divide-zinc-700">
                @foreach ($this->exerciseResults as $exercise)
                    <button type="button" wire:click="addExercise({{ $exercise->id }})" wire:key="pp-{{ $exercise->id }}" class="flex items-center justify-between gap-3 px-1 py-2 text-start hover:bg-zinc-50 dark:hover:bg-zinc-700/50">
                        <span class="font-medium text-zinc-900 dark:text-white">{{ $exercise->name }}</span>
                        <span class="text-sm text-zinc-500">{{ $exercise->muscle_group }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    </flux:modal>
</div>
