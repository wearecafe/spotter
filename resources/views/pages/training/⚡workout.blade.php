<?php

use App\Models\Workout;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Workout')] class extends Component {
    public Workout $workout;

    /** @var array<int, array<int, array{reps: string|null, load: string|null, done: bool}>> keyed by workout_exercise id */
    public array $logs = [];

    public string $feedback = '';

    public function mount(Workout $workout): void
    {
        Gate::authorize('log', $workout);

        $this->workout = $workout->load('items.exercise', 'coach');
        $this->feedback = (string) $workout->client_feedback;

        foreach ($this->workout->items as $item) {
            $this->logs[$item->id] = $item->setsForLogging();
        }
    }

    public function saveProgress(bool $notify = true): void
    {
        $this->validate([
            'logs.*.*.reps' => ['nullable', 'string', 'max:30'],
            'logs.*.*.load' => ['nullable', 'string', 'max:30'],
            'logs.*.*.done' => ['boolean'],
            'feedback' => ['nullable', 'string', 'max:2000'],
        ]);

        foreach ($this->workout->items as $item) {
            $sets = collect($this->logs[$item->id] ?? [])
                ->map(fn (array $set) => [
                    'reps' => filled($set['reps'] ?? null) ? (string) $set['reps'] : null,
                    'load' => filled($set['load'] ?? null) ? (string) $set['load'] : null,
                    'done' => (bool) ($set['done'] ?? false),
                ])
                ->values()
                ->all();

            $item->update(['logged_sets' => $sets]);
        }

        $this->workout->update(['client_feedback' => $this->feedback ?: null]);

        if ($notify) {
            Flux::toast(variant: 'success', text: __('Progress saved.'));
        }
    }

    public function complete(): void
    {
        $this->saveProgress(notify: false);

        $this->workout->update(['completed_at' => now()]);

        Flux::toast(variant: 'success', heading: __('Workout complete!'), text: __('Nice work — your coach can see your results.'));
    }

    public function reopen(): void
    {
        $this->workout->update(['completed_at' => null]);
    }
}; ?>

<div class="mx-auto flex w-full max-w-3xl flex-col gap-6">
    <div>
        <flux:breadcrumbs>
            <flux:breadcrumbs.item :href="route('training.index')" wire:navigate>{{ __('My workouts') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ $workout->scheduled_on?->format('D, M j') }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>
        <flux:heading size="xl" level="1" class="mt-2">{{ $workout->title }}</flux:heading>
        @if ($workout->instructions)
            <flux:text class="mt-2 whitespace-pre-line">{{ $workout->instructions }}</flux:text>
        @endif
    </div>

    @if ($workout->isCompleted())
        <flux:callout icon="check-circle" color="green">
            <flux:callout.heading>{{ __('Completed :when', ['when' => $workout->completed_at->diffForHumans()]) }}</flux:callout.heading>
            <x-slot name="actions">
                <flux:button size="sm" wire:click="reopen">{{ __('Reopen') }}</flux:button>
            </x-slot>
        </flux:callout>
    @endif

    @foreach ($workout->items as $index => $item)
        <flux:card class="flex flex-col gap-4" wire:key="log-{{ $item->id }}">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="text-lg font-semibold text-zinc-900 dark:text-white">{{ $index + 1 }}. {{ $item->exercise->name }}</div>
                    <flux:text>{{ $item->prescription() }}</flux:text>
                    @if ($item->notes)
                        <flux:text size="sm" class="mt-1 italic">{{ $item->notes }}</flux:text>
                    @endif
                </div>
                @if ($item->exercise->video_url)
                    <flux:button size="sm" variant="ghost" icon="play-circle" :href="$item->exercise->video_url" target="_blank">{{ __('Demo') }}</flux:button>
                @endif
            </div>

            <div class="flex flex-col gap-2">
                <div class="grid grid-cols-[3rem_1fr_1fr_3rem] gap-2 text-xs font-medium uppercase text-zinc-500">
                    <span>{{ __('Set') }}</span><span>{{ __('Reps') }}</span><span>{{ __('Load') }}</span><span class="text-center">{{ __('Done') }}</span>
                </div>
                @foreach ($logs[$item->id] as $setIndex => $set)
                    <div class="grid grid-cols-[3rem_1fr_1fr_3rem] items-center gap-2" wire:key="set-{{ $item->id }}-{{ $setIndex }}">
                        <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">{{ $setIndex + 1 }}</span>
                        <flux:input size="sm" wire:model="logs.{{ $item->id }}.{{ $setIndex }}.reps" :placeholder="$item->reps" inputmode="numeric" />
                        <flux:input size="sm" wire:model="logs.{{ $item->id }}.{{ $setIndex }}.load" :placeholder="$item->load" />
                        <div class="flex justify-center"><flux:checkbox wire:model="logs.{{ $item->id }}.{{ $setIndex }}.done" /></div>
                    </div>
                @endforeach
            </div>
        </flux:card>
    @endforeach

    @if ($workout->items->isEmpty())
        <flux:card><flux:text>{{ __('Your coach is still building this workout.') }}</flux:text></flux:card>
    @endif

    <flux:card class="flex flex-col gap-4">
        <flux:textarea wire:model="feedback" :label="__('How did it go?')" :placeholder="__('Anything your coach should know — energy, pain, what felt easy or hard.')" rows="3" />
        <div class="flex flex-wrap justify-end gap-2">
            <flux:button wire:click="saveProgress">{{ __('Save progress') }}</flux:button>
            @unless ($workout->isCompleted())
                <flux:button variant="primary" icon="check" wire:click="complete" data-test="complete-workout-button">{{ __('Complete workout') }}</flux:button>
            @endunless
        </div>
    </flux:card>
</div>
