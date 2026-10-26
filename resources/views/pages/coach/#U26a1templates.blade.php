<?php

use App\Models\Workout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Workout templates')] class extends Component {
    public string $title = '';

    #[Computed]
    public function templates()
    {
        return Auth::user()->templates()
            ->with('items.exercise')
            ->orderBy('title')
            ->get();
    }

    public function create(): void
    {
        $this->validate(['title' => ['required', 'string', 'max:120']]);

        $template = Auth::user()->templates()->create(['title' => $this->title]);

        $this->redirectRoute('coach.workouts.edit', $template, navigate: true);
    }

    public function duplicate(int $id): void
    {
        $template = Workout::findOrFail($id);
        Gate::authorize('update', $template);

        $copy = $template->replicate()->unsetRelations();
        $copy->title = $template->title.' (copy)';
        $copy->save();

        foreach ($template->items as $item) {
            $copy->items()->create($item->only(['exercise_id', 'position', 'sets', 'reps', 'load', 'rest_seconds', 'notes']));
        }

        unset($this->templates);
    }

    public function delete(int $id): void
    {
        $template = Workout::findOrFail($id);
        Gate::authorize('delete', $template);
        $template->delete();

        unset($this->templates);
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Workout templates') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Build a workout once, then schedule it for any client.') }}</flux:text>
    </div>

    <form wire:submit="create" class="flex max-w-lg items-end gap-3">
        <flux:input wire:model="title" :label="__('New template')" :placeholder="__('e.g. Upper body A')" class="flex-1" />
        <flux:button type="submit" variant="primary" icon="plus" data-test="create-template-button">{{ __('Create') }}</flux:button>
    </form>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($this->templates as $template)
            <flux:card class="flex flex-col gap-3" wire:key="template-{{ $template->id }}">
                <div class="flex items-start justify-between gap-2">
                    <a href="{{ route('coach.workouts.edit', $template) }}" wire:navigate class="text-lg font-semibold text-zinc-900 hover:underline dark:text-white">{{ $template->title }}</a>
                    <flux:dropdown align="end">
                        <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" />
                        <flux:menu>
                            <flux:menu.item icon="pencil-square" :href="route('coach.workouts.edit', $template)" wire:navigate>{{ __('Edit') }}</flux:menu.item>
                            <flux:menu.item icon="document-duplicate" wire:click="duplicate({{ $template->id }})">{{ __('Duplicate') }}</flux:menu.item>
                            <flux:menu.item icon="trash" variant="danger" wire:click="delete({{ $template->id }})" wire:confirm="{{ __('Delete this template? Workouts already scheduled are kept.') }}">{{ __('Delete') }}</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </div>
                <ul class="flex flex-col gap-1 text-sm text-zinc-600 dark:text-zinc-300">
                    @forelse ($template->items->take(5) as $item)
                        <li class="flex justify-between gap-2">
                            <span class="truncate">{{ $item->exercise->name }}</span>
                            <span class="shrink-0 text-zinc-500">{{ $item->sets }} × {{ $item->reps ?: '—' }}</span>
                        </li>
                    @empty
                        <li class="text-zinc-500">{{ __('No exercises yet') }}</li>
                    @endforelse
                    @if ($template->items->count() > 5)
                        <li class="text-zinc-500">{{ __('+ :count more', ['count' => $template->items->count() - 5]) }}</li>
                    @endif
                </ul>
            </flux:card>
        @empty
            <flux:text>{{ __('No templates yet. Create your first one above.') }}</flux:text>
        @endforelse
    </div>
</div>
