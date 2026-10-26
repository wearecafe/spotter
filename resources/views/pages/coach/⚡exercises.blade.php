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

new #[Title('Exercises')] class extends Component {
    public string $search = '';

    public string $muscleGroup = '';

    public ?int $editingId = null;

    public string $name = '';

    public string $muscle_group = '';

    public string $equipment = '';

    public string $instructions = '';

    public string $video_url = '';

    #[Computed]
    public function exercises()
    {
        return Exercise::query()
            ->availableTo(Auth::user())
            ->when($this->search, fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
            ->when($this->muscleGroup, fn ($q) => $q->where('muscle_group', $this->muscleGroup))
            ->orderBy('name')
            ->get();
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

        $this->editingId = $exercise->id;
        $this->fill($exercise->only(['name', 'muscle_group', 'equipment', 'instructions', 'video_url']));
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

        if (WorkoutExercise::where('exercise_id', $exercise->id)->exists()) {
            Flux::toast(variant: 'danger', text: __("This exercise is used in workouts, so it can't be deleted."));

            return;
        }

        $exercise->delete();
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
            <flux:text class="mt-1">{{ __('The Spotter library plus your own custom exercises.') }}</flux:text>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="create">{{ __('New exercise') }}</flux:button>
    </div>

    <div class="flex flex-wrap gap-3">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search exercises')" class="max-w-xs" />
        <flux:select wire:model.live="muscleGroup" class="max-w-48">
            <flux:select.option value="">{{ __('All muscle groups') }}</flux:select.option>
            @foreach (\App\Models\Exercise::MUSCLE_GROUPS as $group)
                <flux:select.option :value="$group">{{ $group }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Exercise') }}</flux:table.column>
            <flux:table.column>{{ __('Muscle group') }}</flux:table.column>
            <flux:table.column>{{ __('Equipment') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->exercises as $exercise)
                <flux:table.row :key="$exercise->id">
                    <flux:table.cell>
                        <div class="flex items-center gap-2">
                            <span class="font-medium text-zinc-900 dark:text-white">{{ $exercise->name }}</span>
                            @unless ($exercise->isShared())
                                <flux:badge size="sm" color="indigo">{{ __('Custom') }}</flux:badge>
                            @endunless
                            @if ($exercise->video_url)
                                <a href="{{ $exercise->video_url }}" target="_blank" rel="noopener" class="text-zinc-400 hover:text-zinc-600"><flux:icon.play-circle variant="micro" /></a>
                            @endif
                        </div>
                    </flux:table.cell>
                    <flux:table.cell>{{ $exercise->muscle_group ?? '—' }}</flux:table.cell>
                    <flux:table.cell>{{ $exercise->equipment ?? '—' }}</flux:table.cell>
                    <flux:table.cell align="end">
                        @unless ($exercise->isShared())
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $exercise->id }})" />
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $exercise->id }})" wire:confirm="{{ __('Delete this exercise?') }}" />
                        @endunless
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="4">{{ __('No exercises found.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

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
            <flux:textarea wire:model="instructions" :label="__('Coaching cues')" rows="4" />
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
