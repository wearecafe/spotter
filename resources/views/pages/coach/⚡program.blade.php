<?php

use App\Models\Exercise;
use App\Models\Program;
use App\Models\ProgramDay;
use App\Models\ProgramExercise;
use App\Models\Workout;
use App\Models\WorkoutExercise;
use App\Services\ProgramScheduler;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Program')] class extends Component {
    public Program $program;

    public string $title = '';

    public string $startsOn = '';

    public int $weeks = 6;

    /** @var array<int, array<string, string>> blueprint exercise cells keyed by program_exercise id */
    public array $rows = [];

    /** @var array<int, array<string, string>> day fields keyed by program_day id */
    public array $dayFields = [];

    public ?int $historyFor = null;

    public ?int $addingToDay = null;

    public string $exerciseSearch = '';

    public const EDITABLE = ['sets', 'reps', 'load', 'target_rpe', 'rest_seconds', 'progression', 'progression_step'];

    /**
     * Re-checked on every action: access can change while the page is open.
     */
    public function hydrate(): void
    {
        Gate::authorize('manage-program', $this->program);
    }

    public function mount(Program $program): void
    {
        Gate::authorize('manage-program', $program);

        $this->program = $program->load('client');
        $this->title = $program->title;
        $this->startsOn = $program->starts_on->toDateString();
        $this->weeks = $program->planned_weeks;
        $this->loadBlueprint();
    }

    protected function scheduler(): ProgramScheduler
    {
        return app(ProgramScheduler::class);
    }

    protected function loadBlueprint(): void
    {
        unset($this->days, $this->calendar, $this->history);
        $this->rows = [];
        $this->dayFields = [];

        foreach ($this->days as $day) {
            $this->dayFields[$day->id] = ['title' => $day->title, 'weekday' => (string) $day->weekday, 'notes' => (string) $day->notes];

            foreach ($day->exercises as $row) {
                $this->rows[$row->id] = collect(self::EDITABLE)->mapWithKeys(fn ($f) => [$f => (string) $row->{$f}])->all();
            }
        }
    }

    #[Computed]
    public function days()
    {
        return $this->program->days()->with('exercises.exercise')->get();
    }

    /**
     * Every week's session for every day, with its status.
     *
     * @return array<int, array<int, array{session: Workout|null, status: string, date: \Carbon\CarbonInterface}>>
     */
    #[Computed]
    public function calendar(): array
    {
        $sessions = Workout::query()->where('program_id', $this->program->id)->whereNotNull('program_day_id')->with('items')->get()
            ->groupBy('program_day_id')->map->keyBy('week');
        $lastWeek = max($this->program->planned_weeks, (int) Workout::where('program_id', $this->program->id)->max('week'));
        $calendar = [];

        for ($week = 1; $week <= $lastWeek; $week++) {
            foreach ($this->days as $day) {
                $session = $sessions->get($day->id)?->get($week);
                $date = $session?->scheduled_on ?? $this->program->dateFor($week, $day->weekday);

                $status = match (true) {
                    $session === null && in_array($week, $day->skipped_weeks ?? [], true) => 'skipped',
                    $session === null => $date->lt($this->program->starts_on) ? 'before' : 'none',
                    $session->isCompleted() => 'done',
                    $session->isStarted() => 'started',
                    $session->scheduled_on?->lt(today()) => 'missed',
                    $session->customized_at !== null => 'edited',
                    default => 'planned',
                };

                $calendar[$week][$day->id] = ['session' => $session, 'status' => $status, 'date' => $date];
            }
        }

        return $calendar;
    }

    /**
     * Planned vs actual, week by week, for one blueprint exercise.
     */
    #[Computed]
    public function history()
    {
        if (! $this->historyFor) {
            return collect();
        }

        return WorkoutExercise::query()
            ->where('program_exercise_id', $this->historyFor)
            ->whereHas('workout', fn ($q) => $q->where('program_id', $this->program->id))
            ->with('workout:id,week,scheduled_on,completed_at,customized_at')
            ->get()
            ->sortBy(fn ($i) => $i->workout->week)
            ->values();
    }

    public function saveSettings(): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:120'],
            'startsOn' => ['required', 'date'],
            'weeks' => ['required', 'integer', 'between:1,52'],
        ], attributes: ['startsOn' => __('start date')]);

        $this->program->update(['title' => $this->title, 'planned_weeks' => $this->weeks]);

        if (! $this->program->starts_on->isSameDay(\Illuminate\Support\Carbon::parse($this->startsOn))) {
            if ($problem = $this->scheduler()->moveStart($this->program, $this->startsOn)) {
                $this->addError('startsOn', $problem);
                $this->startsOn = $this->program->starts_on->toDateString();

                return;
            }

            $this->program->refresh();
            $this->weeks = $this->program->planned_weeks;
        }

        $this->scheduler()->sync($this->program);
        $this->loadBlueprint();

        Flux::toast(variant: 'success', text: __('Saved. Upcoming sessions were updated.'));
    }

    public function extend(int $weeks = 4): void
    {
        $this->weeks = min($this->program->planned_weeks + max(1, min($weeks, 12)), 52);
        $this->saveSettings();
    }

    /**
     * Blueprint cells save as the coach edits them, and every open session follows.
     */
    public function updatedRows(mixed $value, string $key): void
    {
        [$id, $field] = explode('.', $key, 2) + [null, null];

        if (! in_array($field, self::EDITABLE, true)) {
            return;
        }

        $row = $this->blueprintRow((int) $id);

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

        $changes = [$field => $value === '' ? null : $value];

        // A new starting load or rep target applies from the next session that hasn't happened yet.
        if (in_array($field, ['load', 'reps'], true)) {
            $changes['base_week'] = $this->scheduler()->firstOpenWeek($row->day);
        }

        $row->update($changes);
        $this->scheduler()->syncDay($row->day);
        unset($this->calendar, $this->history);
    }

    public function updatedDayFields(mixed $value, string $key): void
    {
        [$id, $field] = explode('.', $key, 2) + [null, null];

        if (! in_array($field, ['title', 'weekday', 'notes'], true)) {
            return;
        }

        $day = $this->blueprintDay((int) $id);

        $this->validate(["dayFields.$id.$field" => match ($field) {
            'title' => ['required', 'string', 'max:120'],
            'weekday' => ['required', 'integer', 'between:1,7'],
            'notes' => ['nullable', 'string', 'max:5000'],
        }], attributes: ["dayFields.$id.$field" => __($field)]);

        $day->update([$field => $value === '' ? null : $value]);
        $this->scheduler()->syncDay($day);
        unset($this->days, $this->calendar);
    }

    public function addDay(): void
    {
        $used = $this->days->pluck('weekday')->all();
        $weekday = collect([1, 2, 3, 4, 5, 6, 7])->first(fn ($d) => ! in_array($d, $used, true)) ?? 1;
        $position = (int) $this->days->max('position') + 1;

        $day = $this->program->days()->create(['position' => $position, 'title' => __('Day :label', ['label' => chr(64 + min($position, 26))]), 'weekday' => $weekday]);
        $this->scheduler()->syncDay($day);
        $this->loadBlueprint();
    }

    public function removeDay(int $id): void
    {
        $day = $this->blueprintDay($id);

        DB::transaction(function () use ($day) {
            // Sessions that haven't happened go; history the client logged stays on their calendar.
            Workout::query()->where('program_day_id', $day->id)->with('items')->get()->filter->followsProgram()->each->delete();
            $day->delete();
        });

        $this->loadBlueprint();
    }

    public function openPicker(int $dayId): void
    {
        $this->blueprintDay($dayId);
        $this->addingToDay = $dayId;
        $this->exerciseSearch = '';
        Flux::modal('program-exercise-picker')->show();
    }

    #[Computed]
    public function exerciseResults()
    {
        return Exercise::query()
            ->availableTo(Auth::user())
            ->when($this->exerciseSearch, fn ($q) => $q->where('name', 'like', "%{$this->exerciseSearch}%"))
            ->orderByRaw('case when name like ? then 0 else 1 end', [$this->exerciseSearch.'%'])
            ->orderByRaw("case when coach_id is not null then 0 when source = 'free-exercise-db' then 2 else 1 end")
            ->orderBy('name')
            ->limit(20)
            ->get();
    }

    public function addExercise(int $exerciseId): void
    {
        $day = $this->blueprintDay((int) $this->addingToDay);
        $exercise = Exercise::query()->availableTo(Auth::user())->findOrFail($exerciseId);

        $day->exercises()->create([
            'exercise_id' => $exercise->id,
            'position' => (int) $day->exercises()->max('position') + 1,
            'sets' => 3,
            'reps' => '8',
            'progression' => in_array($exercise->category, ['stretching', 'cardio'], true) ? 'none' : 'load',
            'base_week' => $this->scheduler()->firstOpenWeek($day),
        ]);

        $this->scheduler()->syncDay($day);
        Flux::modal('program-exercise-picker')->close();
        $this->loadBlueprint();
    }

    public function removeExercise(int $id): void
    {
        $row = $this->blueprintRow($id);
        $day = $row->day;
        $row->delete();

        if ($this->historyFor === $id) {
            $this->historyFor = null;
        }

        $this->scheduler()->syncDay($day);
        $this->loadBlueprint();
    }

    public function moveExercise(int $id, int $direction): void
    {
        $row = $this->blueprintRow($id);
        $siblings = $row->day->exercises()->get()->values();
        $index = $siblings->search(fn ($r) => $r->id === $row->id);
        $other = $siblings->get($index + $direction);

        if (! $other) {
            return;
        }

        $siblings[$index] = $other;
        $siblings[$index + $direction] = $row;
        $siblings->each(fn ($r, $i) => $r->update(['position' => $i]));

        $this->scheduler()->syncDay($row->day);
        $this->loadBlueprint();
    }

    public function unskip(int $dayId, int $week): void
    {
        $this->scheduler()->unskip($this->blueprintDay($dayId), $week);
        unset($this->calendar, $this->days);
    }

    public function showHistory(int $id): void
    {
        $this->historyFor = $this->historyFor === $id ? null : $id;
        unset($this->history);
    }

    protected function blueprintDay(int $id): ProgramDay
    {
        return $this->program->days()->whereKey($id)->firstOrFail();
    }

    protected function blueprintRow(int $id): ProgramExercise
    {
        return ProgramExercise::query()->whereKey($id)->whereHas('day', fn ($q) => $q->where('program_id', $this->program->id))->with('day.program')->firstOrFail();
    }
}; ?>

@php($weekdayNames = collect(range(1, 7))->mapWithKeys(fn ($d) => [$d => now()->startOfWeek()->addDays($d - 1)->translatedFormat('l')]))
<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:breadcrumbs>
                <flux:breadcrumbs.item :href="route('coach.clients.index')" wire:navigate>{{ __('Clients') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item :href="route('coach.clients.show', $program->client)" wire:navigate>{{ $program->client->name }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ $program->title }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>
            <flux:heading size="xl" level="1" class="mt-2">{{ $program->title }}</flux:heading>
            <flux:text class="mt-1">
                {{ trans_choice(':count day a week|:count days a week', $this->days->count()) }}@if ($this->days->isNotEmpty()) ({{ $this->days->sortBy('weekday')->map(fn ($d) => now()->startOfWeek()->addDays($d->weekday - 1)->translatedFormat('D'))->implode(', ') }})@endif
                · {{ trans_choice(':count week|:count weeks', $program->planned_weeks) }}
                · {{ $program->starts_on->translatedFormat('j M') }} – {{ $program->endsOn()->translatedFormat('j M Y') }}
            </flux:text>
        </div>
    </div>

    <flux:callout icon="arrow-path">
        <flux:callout.text>{{ __('Edit the program once here. Every upcoming session follows, unless the client has started it or you edited it by hand. Loads for future weeks are planned from your rules and re-planned each time :name finishes a session.', ['name' => str($program->client->name)->before(' ')]) }}</flux:callout.text>
    </flux:callout>

    <flux:card>
        <form wire:submit="saveSettings" class="grid gap-4 sm:grid-cols-[1fr_11rem_7rem_auto_auto] sm:items-end">
            <flux:input wire:model="title" :label="__('Program name')" />
            <flux:input wire:model="startsOn" type="date" :label="__('Starts')" />
            <flux:input wire:model="weeks" type="number" min="1" max="52" :label="__('Weeks')" />
            <flux:button type="submit" variant="primary" data-test="save-program">{{ __('Save') }}</flux:button>
            <flux:button type="button" icon="plus" wire:click="extend(4)" data-test="extend-program">{{ __('Add 4 weeks') }}</flux:button>
        </form>
    </flux:card>

    <div class="grid gap-6 2xl:grid-cols-[minmax(0,1fr)_24rem]">
        <div class="flex min-w-0 flex-col gap-5">
            @forelse ($this->days as $day)
                <flux:card class="flex min-w-0 flex-col gap-0 p-0!" wire:key="day-{{ $day->id }}">
                    <div class="grid gap-3 border-b border-zinc-200 px-4 py-3 sm:grid-cols-[minmax(0,1fr)_11rem_auto] sm:items-end dark:border-zinc-700">
                        <flux:input wire:model.blur="dayFields.{{ $day->id }}.title" :label="__('Day')" size="sm" />
                        <flux:select wire:model.live="dayFields.{{ $day->id }}.weekday" :label="__('Every')" size="sm">
                            @foreach ($weekdayNames as $n => $name)
                                <flux:select.option :value="$n">{{ $name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <div class="flex gap-1">
                            <flux:button size="sm" icon="plus" wire:click="openPicker({{ $day->id }})">{{ __('Exercise') }}</flux:button>
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeDay({{ $day->id }})" wire:confirm="{{ __('Remove :day from the program? Upcoming sessions are removed; anything already logged stays.', ['day' => $day->title]) }}" :aria-label="__('Remove day')" />
                        </div>
                        @error("dayFields.{$day->id}.title")<div class="text-sm text-red-600 sm:col-span-3">{{ $message }}</div>@enderror
                    </div>

                    <div class="overflow-x-auto px-2 pb-2">
                        <table class="w-full min-w-[54rem] text-sm">
                            <thead>
                                <tr class="text-xs uppercase tracking-wide text-zinc-500">
                                    <th scope="col" class="px-2 py-2 text-start font-medium">{{ __('Exercise') }}</th>
                                    <th scope="col" class="w-16 px-1 py-2 text-start font-medium">{{ __('Sets') }}</th>
                                    <th scope="col" class="w-20 px-1 py-2 text-start font-medium">{{ __('Reps') }}</th>
                                    <th scope="col" class="w-24 px-1 py-2 text-start font-medium">{{ __('Start load') }}</th>
                                    <th scope="col" class="w-16 px-1 py-2 text-start font-medium">RPE</th>
                                    <th scope="col" class="w-20 px-1 py-2 text-start font-medium">{{ __('Rest s') }}</th>
                                    <th scope="col" class="w-32 px-1 py-2 text-start font-medium">{{ __('Each week') }}</th>
                                    <th scope="col" class="w-20 px-1 py-2 text-start font-medium">{{ __('Step') }}</th>
                                    <th scope="col" class="w-24 px-1 py-2"><span class="sr-only">{{ __('Actions') }}</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($day->exercises as $row)
                                    <tr wire:key="row-{{ $row->id }}" @class(['border-t border-zinc-100 dark:border-zinc-700', 'bg-zinc-50 dark:bg-zinc-800/60' => $historyFor === $row->id])>
                                        <td class="px-2 py-1.5">
                                            <button type="button" wire:click="showHistory({{ $row->id }})" class="flex items-center gap-2 text-start font-medium text-zinc-900 hover:underline dark:text-white">
                                                @if ($thumb = $row->exercise->imageUrls()[0] ?? null)
                                                    <img src="{{ $thumb }}" alt="" loading="lazy" class="size-8 shrink-0 rounded bg-zinc-100 object-cover">
                                                @endif
                                                <span>{{ $row->exercise->name }}</span>
                                            </button>
                                            @if ($row->base_week > 1)
                                                <div class="text-xs text-zinc-500">{{ __('Values apply from week :n', ['n' => $row->base_week]) }}</div>
                                            @endif
                                        </td>
                                        @foreach (['sets' => 'number', 'reps' => 'text', 'load' => 'text', 'target_rpe' => 'text', 'rest_seconds' => 'number'] as $field => $inputType)
                                            <td class="px-1 py-1.5">
                                                <input type="{{ $inputType }}" wire:model.blur="rows.{{ $row->id }}.{{ $field }}"
                                                    aria-label="{{ __(':field for :exercise', ['field' => str_replace('_', ' ', $field), 'exercise' => $row->exercise->name]) }}"
                                                    class="h-9 w-full rounded-md border border-zinc-200 bg-white px-2 tabular-nums dark:border-zinc-600 dark:bg-zinc-900">
                                            </td>
                                        @endforeach
                                        <td class="px-1 py-1.5">
                                            <select wire:model.live="rows.{{ $row->id }}.progression" aria-label="{{ __('Progression rule for :exercise', ['exercise' => $row->exercise->name]) }}" class="h-9 w-full rounded-md border border-zinc-200 bg-white px-2 dark:border-zinc-600 dark:bg-zinc-900">
                                                @foreach (\App\Models\WorkoutExercise::PROGRESSIONS as $key => $label)
                                                    <option value="{{ $key }}">{{ __($label) }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="px-1 py-1.5">
                                            <input type="text" wire:model.blur="rows.{{ $row->id }}.progression_step"
                                                placeholder="{{ ($rows[$row->id]['progression'] ?? '') === 'reps' ? '1' : \App\Support\Load::defaultStep($row->load) }}"
                                                aria-label="{{ __('Step for :exercise', ['exercise' => $row->exercise->name]) }}"
                                                @disabled(in_array($rows[$row->id]['progression'] ?? 'none', ['none', 'hold'], true))
                                                class="h-9 w-full rounded-md border border-zinc-200 bg-white px-2 disabled:opacity-40 dark:border-zinc-600 dark:bg-zinc-900">
                                        </td>
                                        <td class="px-1 py-1.5 text-end whitespace-nowrap">
                                            <flux:button size="xs" variant="ghost" icon="chevron-up" wire:click="moveExercise({{ $row->id }}, -1)" :disabled="$loop->first" :aria-label="__('Move up')" />
                                            <flux:button size="xs" variant="ghost" icon="chevron-down" wire:click="moveExercise({{ $row->id }}, 1)" :disabled="$loop->last" :aria-label="__('Move down')" />
                                            <flux:button size="xs" variant="ghost" icon="trash" wire:click="removeExercise({{ $row->id }})" wire:confirm="{{ __('Remove :exercise from every upcoming :day?', ['exercise' => $row->exercise->name, 'day' => $day->title]) }}" :aria-label="__('Remove')" />
                                        </td>
                                    </tr>
                                    @foreach ($this::EDITABLE as $field)
                                        @error("rows.{$row->id}.{$field}")
                                            <tr><td colspan="9" class="px-2 pb-2 text-sm text-red-600">{{ $message }}</td></tr>
                                        @enderror
                                    @endforeach
                                @endforeach
                            </tbody>
                        </table>
                        @if ($day->exercises->isEmpty())
                            <flux:text class="px-2 py-4">{{ __('No exercises yet. Add the first one and it appears in every week.') }}</flux:text>
                        @endif
                    </div>
                    <div class="border-t border-zinc-100 px-4 py-3 dark:border-zinc-700">
                        <flux:textarea wire:model.blur="dayFields.{{ $day->id }}.notes" rows="1" :placeholder="__('Note for the client on this day (warm-up, focus…)')" :aria-label="__('Note for the client')" />
                    </div>
                </flux:card>
            @empty
                <flux:card><flux:text>{{ __('No training days yet.') }}</flux:text></flux:card>
            @endforelse

            <flux:button icon="plus" class="self-start" wire:click="addDay" data-test="add-day">{{ __('Add a training day') }}</flux:button>
        </div>

        <aside class="flex min-w-0 flex-col gap-5">
            @if ($this->history->isNotEmpty())
                <flux:card class="flex flex-col gap-3">
                    <div class="flex items-center justify-between gap-2">
                        <flux:heading>{{ $this->days->flatMap->exercises->firstWhere('id', $historyFor)?->exercise->name }}</flux:heading>
                        <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="showHistory({{ $historyFor }})" :aria-label="__('Close')" />
                    </div>
                    <table class="w-full text-sm">
                        <thead><tr class="text-xs uppercase text-zinc-500"><th class="py-1 text-start font-medium">{{ __('Wk') }}</th><th class="py-1 text-start font-medium">{{ __('Planned') }}</th><th class="py-1 text-start font-medium">{{ __('Actual') }}</th></tr></thead>
                        <tbody>
                            @foreach ($this->history as $h)
                                <tr class="border-t border-zinc-100 align-top dark:border-zinc-700">
                                    <td class="py-1.5 pe-2 tabular-nums">{{ $h->workout->week }}</td>
                                    <td class="py-1.5 pe-2">
                                        <span @class(['rounded px-1 tabular-nums', 'cell-changed font-semibold' => ! empty($h->changed_fields)])>{{ $h->sets }} × {{ $h->reps ?: '—' }}{{ $h->load ? ' @ '.$h->load : '' }}</span>
                                        @if ($h->change_reason)<div class="text-xs text-zinc-500">{{ $h->change_reason }}</div>@endif
                                    </td>
                                    <td class="py-1.5 tabular-nums text-zinc-600 dark:text-zinc-300">
                                        {{ collect($h->logged_sets ?? [])->filter(fn ($s) => $s['done'] ?? false)->map(fn ($s) => ($s['reps'] ?? '—').(($s['load'] ?? null) ? '@'.$s['load'] : '').(($s['rpe'] ?? null) ? ' ('.$s['rpe'].')' : ''))->implode(', ') ?: ($h->workout->isCompleted() ? '—' : __('not yet')) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </flux:card>
            @endif

            <flux:card class="flex flex-col gap-3 p-0!">
                <flux:heading class="px-4 pt-4">{{ __('Calendar') }}</flux:heading>
                <div class="overflow-x-auto px-2 pb-3">
                    <table class="w-full text-sm">
                        <thead><tr class="text-xs uppercase text-zinc-500">
                            <th class="px-2 py-1 text-start font-medium">{{ __('Wk') }}</th>
                            @foreach ($this->days as $day)
                                <th class="px-2 py-1 text-start font-medium">{{ $day->title }}</th>
                            @endforeach
                        </tr></thead>
                        <tbody>
                            @foreach ($this->calendar as $week => $cells)
                                <tr class="border-t border-zinc-100 dark:border-zinc-700" wire:key="cal-{{ $week }}">
                                    <td class="px-2 py-1.5 tabular-nums text-zinc-500">{{ $week }}</td>
                                    @foreach ($cells as $dayId => $cell)
                                        <td class="px-2 py-1.5">
                                            @php($label = $cell['date']->translatedFormat('D j M'))
                                            @if ($cell['session'])
                                                <a href="{{ route('coach.workouts.edit', $cell['session']) }}" wire:navigate class="block hover:underline">
                                                    <span class="block whitespace-nowrap tabular-nums">{{ $label }}</span>
                                                </a>
                                            @else
                                                <span class="block whitespace-nowrap tabular-nums text-zinc-400">{{ $label }}</span>
                                            @endif
                                            @switch($cell['status'])
                                                @case('done') <flux:badge size="sm" color="green">{{ __('Done') }}</flux:badge> @break
                                                @case('started') <flux:badge size="sm" color="blue">{{ __('In progress') }}</flux:badge> @break
                                                @case('missed') <flux:badge size="sm" color="red">{{ __('Missed') }}</flux:badge> @break
                                                @case('edited') <flux:badge size="sm" color="amber">{{ __('Edited') }}</flux:badge> @break
                                                @case('skipped')
                                                    <button type="button" wire:click="unskip({{ $dayId }}, {{ $week }})" class="text-xs font-semibold text-zinc-500 underline">{{ __('Skipped · restore') }}</button>
                                                    @break
                                                @case('before') <span class="text-xs text-zinc-400">{{ __('Before start') }}</span> @break
                                                @case('none') <span class="text-xs text-zinc-400">—</span> @break
                                                @default <span class="text-xs text-zinc-500">{{ __('Planned') }}</span>
                                            @endswitch
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </flux:card>

            <flux:card class="flex flex-col gap-2">
                <flux:heading>{{ __('How rules work') }}</flux:heading>
                <flux:text size="sm">{{ __('Each week a rule checks the session before it. Every set done, reps hit and RPE at or under target: the step is added. Otherwise the row holds. Weeks that haven’t happened yet show the plan if everything goes to plan.') }}</flux:text>
                <ul class="flex flex-col gap-1 text-sm text-zinc-700 dark:text-zinc-300">
                    <li><strong>{{ __('Add load') }}</strong>: {{ __('adds the step, e.g. 2.5 kg or 5%') }}</li>
                    <li><strong>{{ __('Add reps') }}</strong>: {{ __('climbs a rep range, e.g. 8-10 to 9-10') }}</li>
                    <li><strong>{{ __('Hold') }}</strong> / <strong>{{ __('No rule') }}</strong>: {{ __('repeats the row unchanged') }}</li>
                </ul>
            </flux:card>
        </aside>
    </div>

    <flux:modal name="program-exercise-picker" class="w-full md:w-[32rem]">
        <div class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Add an exercise') }}</flux:heading>
            <flux:input wire:model.live.debounce.250ms="exerciseSearch" icon="magnifying-glass" :placeholder="__('Search exercises')" autofocus />
            <div class="flex max-h-96 flex-col divide-y divide-zinc-100 overflow-y-auto dark:divide-zinc-700">
                @foreach ($this->exerciseResults as $exercise)
                    <button type="button" wire:click="addExercise({{ $exercise->id }})" wire:key="pp-{{ $exercise->id }}" class="flex items-center justify-between gap-3 px-1 py-2 text-start hover:bg-zinc-50 dark:hover:bg-zinc-700/50">
                        <span class="flex min-w-0 items-center gap-3">
                            @if ($thumb = $exercise->imageUrls()[0] ?? null)
                                <img src="{{ $thumb }}" alt="" loading="lazy" class="size-9 shrink-0 rounded bg-zinc-100 object-cover">
                            @else
                                <span class="size-9 shrink-0 rounded bg-zinc-100 dark:bg-zinc-800"></span>
                            @endif
                            <span class="truncate font-medium text-zinc-900 dark:text-white">{{ $exercise->name }}</span>
                        </span>
                        <span class="shrink-0 text-sm text-zinc-500">{{ collect([$exercise->muscle_group, $exercise->equipment])->filter()->implode(' · ') }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    </flux:modal>
</div>
