<?php

use App\Models\Exercise;
use App\Models\WorkoutExercise;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Exercises')] class extends Component {
    use WithPagination;

    public string $search = '';

    public string $muscleGroup = '';

    public string $equipmentFilter = '';

    public string $level = '';

    public string $library = '';

    public ?int $viewingId = null;

    public ?int $editingId = null;

    public string $name = '';

    public string $muscle_group = '';

    public string $equipment = '';

    public string $instructions = '';

    public string $video_url = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'muscleGroup', 'equipmentFilter', 'level', 'library'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function exercises()
    {
        return Exercise::query()
            ->availableTo(Auth::user())
            ->when($this->search, fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
            ->when($this->muscleGroup, fn ($q) => $q->where(fn ($q) => $q->where('muscle_group', $this->muscleGroup)
                ->orWhere('secondary_muscles', 'like', '%"'.$this->muscleGroup.'"%')))
            ->when($this->equipmentFilter, fn ($q) => $q->where('equipment', $this->equipmentFilter))
            ->when(in_array($this->level, ['beginner', 'intermediate', 'expert'], true), fn ($q) => $q->where('level', $this->level))
            ->when($this->library === 'team', fn ($q) => $q->whereNotNull('coach_id'))
            ->when($this->library === 'shared', fn ($q) => $q->whereNull('coach_id'))
            // Your team's exercises first, then the shared library.
            ->orderByRaw('case when coach_id is null then 1 else 0 end')
            ->orderBy('name')
            ->paginate(30);
    }

    #[Computed]
    public function equipmentOptions()
    {
        return Exercise::query()->availableTo(Auth::user())->whereNotNull('equipment')->distinct()->orderBy('equipment')->pluck('equipment');
    }

    #[Computed]
    public function viewing(): ?Exercise
    {
        return $this->viewingId ? Exercise::query()->availableTo(Auth::user())->find($this->viewingId) : null;
    }

    public function openExercise(int $id): void
    {
        $this->viewingId = $id;
        unset($this->viewing);
        Flux::modal('exercise-detail')->show();
    }

    /**
     * Copy a library exercise into the team's own list, to rename it or change the cues.
     */
    public function copyToMine(int $id): void
    {
        $original = Exercise::query()->availableTo(Auth::user())->findOrFail($id);

        $copy = Auth::user()->exercises()->create($original->only(['name', 'muscle_group', 'secondary_muscles', 'equipment', 'category', 'level', 'instructions', 'video_url', 'images']));

        Flux::modal('exercise-detail')->close();
        Flux::toast(variant: 'success', text: __('Copied to your exercises.'));
        $this->edit($copy->id);
    }

    public function create(): void
    {
        $this->resetForm();
        Flux::modal('exercise-form')->show();
    }

    public function edit(int $id): void
    {
        $exercise = Exercise::findOrFail($id);
        Gate::authorize('update', $exercise);

        Flux::modal('exercise-detail')->close();
        $this->editingId = $exercise->id;
        $this->name = $exercise->name;
        $this->muscle_group = (string) $exercise->muscle_group;
        $this->equipment = (string) $exercise->equipment;
        $this->instructions = (string) $exercise->instructions;
        $this->video_url = (string) $exercise->video_url;

        Flux::modal('exercise-form')->show();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'muscle_group' => ['nullable', Rule::in(Exercise::MUSCLE_GROUPS)],
            'equipment' => ['nullable', 'string', 'max:60'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'video_url' => ['nullable', 'url', 'max:255'],
        ]);

        $validated = array_map(fn ($v) => $v === '' ? null : $v, $validated);

        if ($this->editingId) {
            $exercise = Exercise::findOrFail($this->editingId);
            Gate::authorize('update', $exercise);
            $exercise->update($validated);
        } else {
            Auth::user()->exercises()->create($validated);
        }

        Flux::modal('exercise-form')->close();
        Flux::toast(variant: 'success', text: __('Exercise saved.'));
        $this->resetForm();
    }

    public function delete(int $id): void
    {
        $exercise = Exercise::findOrFail($id);
        Gate::authorize('delete', $exercise);

        if (WorkoutExercise::where('exercise_id', $exercise->id)->exists() || \App\Models\ProgramExercise::where('exercise_id', $exercise->id)->exists()) {
            Flux::toast(variant: 'danger', text: __("This exercise is used in workouts or programs, so it can't be deleted."));

            return;
        }

        $exercise->delete();
        Flux::modal('exercise-detail')->close();
    }

    protected function resetForm(): void
    {
        $this->reset('editingId', 'name', 'muscle_group', 'equipment', 'instructions', 'video_url');
        $this->resetValidation();
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Exercises') }}</flux:heading>
            <flux:text class="mt-1">{{ __(':count exercises: the Spotter library plus your team’s own.', ['count' => number_format($this->exercises->total())]) }}</flux:text>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="create">{{ __('New exercise') }}</flux:button>
    </div>

    <div class="flex flex-wrap gap-3">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search exercises')" class="max-w-xs" />
        <flux:select wire:model.live="muscleGroup" class="max-w-44" :aria-label="__('Muscle group')">
            <flux:select.option value="">{{ __('All muscle groups') }}</flux:select.option>
            @foreach (\App\Models\Exercise::MUSCLE_GROUPS as $group)
                <flux:select.option :value="$group">{{ __($group) }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="equipmentFilter" class="max-w-44" :aria-label="__('Equipment')">
            <flux:select.option value="">{{ __('Any equipment') }}</flux:select.option>
            @foreach ($this->equipmentOptions as $option)
                <flux:select.option :value="$option">{{ __($option) }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="level" class="max-w-40" :aria-label="__('Level')">
            <flux:select.option value="">{{ __('Any level') }}</flux:select.option>
            <flux:select.option value="beginner">{{ __('Beginner') }}</flux:select.option>
            <flux:select.option value="intermediate">{{ __('Intermediate') }}</flux:select.option>
            <flux:select.option value="expert">{{ __('Expert') }}</flux:select.option>
        </flux:select>
        <flux:select wire:model.live="library" class="max-w-44" :aria-label="__('Library')">
            <flux:select.option value="">{{ __('All exercises') }}</flux:select.option>
            <flux:select.option value="team">{{ __('Your team’s') }}</flux:select.option>
            <flux:select.option value="shared">{{ __('Spotter library') }}</flux:select.option>
        </flux:select>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        @forelse ($this->exercises as $exercise)
            <button type="button" wire:click="openExercise({{ $exercise->id }})" wire:key="ex-{{ $exercise->id }}"
                class="flex items-center gap-3 rounded-xl border border-zinc-200 bg-white p-3 text-start transition hover:border-zinc-400 dark:border-zinc-700 dark:bg-zinc-900">
                @if ($thumb = $exercise->imageUrls()[0] ?? null)
                    <img src="{{ $thumb }}" alt="" loading="lazy" class="size-16 shrink-0 rounded-lg bg-zinc-100 object-cover">
                @else
                    <span class="flex size-16 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-zinc-400 dark:bg-zinc-800"><flux:icon.bolt /></span>
                @endif
                <span class="flex min-w-0 flex-col gap-0.5">
                    <span class="truncate font-semibold text-zinc-900 dark:text-white">{{ $exercise->name }}</span>
                    <span class="truncate text-sm text-zinc-500">{{ collect([$exercise->muscle_group ? __($exercise->muscle_group) : null, $exercise->equipment ? __($exercise->equipment) : null])->filter()->implode(' · ') ?: '—' }}</span>
                    <span class="flex gap-1">
                        @unless ($exercise->isShared())
                            <flux:badge size="sm" color="indigo">{{ __('Custom') }}</flux:badge>
                        @endunless
                        @if ($exercise->level)
                            <flux:badge size="sm">{{ __(ucfirst($exercise->level)) }}</flux:badge>
                        @endif
                    </span>
                </span>
            </button>
        @empty
            <flux:text>{{ __('No exercises found.') }}</flux:text>
        @endforelse
    </div>

    {{ $this->exercises->links() }}

    <flux:modal name="exercise-detail" class="w-full md:w-[40rem]">
        @if ($ex = $this->viewing)
            <div class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ $ex->name }}</flux:heading>
                    <flux:text class="mt-1">{{ collect([$ex->muscle_group ? __($ex->muscle_group) : null, $ex->equipment ? __($ex->equipment) : null, $ex->level ? __(ucfirst($ex->level)) : null])->filter()->implode(' · ') }}</flux:text>
                    @if ($ex->secondary_muscles)
                        <flux:text size="sm" class="mt-1">{{ __('Also works: :muscles', ['muscles' => collect($ex->secondary_muscles)->map(fn ($m) => __($m))->implode(', ')]) }}</flux:text>
                    @endif
                </div>
                @if ($ex->imageUrls())
                    <div class="grid grid-cols-2 gap-2">
                        @foreach ($ex->imageUrls() as $i => $url)
                            <img src="{{ $url }}" alt="{{ __(':name, :position position', ['name' => $ex->name, 'position' => $i === 0 ? __('start') : __('end')]) }}" class="aspect-[4/3] w-full rounded-lg bg-zinc-100 object-cover">
                        @endforeach
                    </div>
                @endif
                @if ($ex->instructionSteps())
                    <ol class="flex list-decimal flex-col gap-2 ps-5 text-[15px] leading-relaxed text-zinc-700 dark:text-zinc-300">
                        @foreach ($ex->instructionSteps() as $step)
                            <li>{{ $step }}</li>
                        @endforeach
                    </ol>
                @endif
                @if ($ex->video_url)
                    <a href="{{ $ex->video_url }}" target="_blank" rel="noopener" class="text-sm font-semibold text-brand hover:underline dark:text-lime">{{ __('Watch demo video') }}</a>
                @endif
                @if ($ex->source === 'free-exercise-db' || ($ex->source === 'spotter' && $ex->source_id))
                    <flux:text size="sm">{{ __('Photos and instructions from free-exercise-db (public domain).') }}</flux:text>
                @endif
                <div class="flex flex-wrap justify-end gap-2">
                    @if ($ex->isShared())
                        <flux:button icon="document-duplicate" wire:click="copyToMine({{ $ex->id }})">{{ __('Copy to edit') }}</flux:button>
                    @else
                        @can('update', $ex)
                            <flux:button icon="pencil-square" wire:click="edit({{ $ex->id }})">{{ __('Edit') }}</flux:button>
                        @endcan
                        @can('delete', $ex)
                            <flux:button variant="danger" icon="trash" wire:click="delete({{ $ex->id }})" wire:confirm="{{ __('Delete this exercise?') }}">{{ __('Delete') }}</flux:button>
                        @endcan
                    @endif
                </div>
            </div>
        @endif
    </flux:modal>

    <flux:modal name="exercise-form" class="md:w-[28rem]">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit exercise') : __('New exercise') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Name')" required />
            <div class="grid grid-cols-2 gap-4">
                <flux:select wire:model="muscle_group" :label="__('Muscle group')">
                    <flux:select.option value="">{{ __('—') }}</flux:select.option>
                    @foreach (\App\Models\Exercise::MUSCLE_GROUPS as $group)
                        <flux:select.option :value="$group">{{ $group }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="equipment" :label="__('Equipment')" />
            </div>
            <flux:textarea wire:model="instructions" :label="__('Coaching cues')" :description="__('One step per line.')" rows="5" />
            <flux:input wire:model="video_url" :label="__('Demo video URL')" type="url" placeholder="https://" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
