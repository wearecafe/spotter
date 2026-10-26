<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('My workouts')] class extends Component {
    #[Computed]
    public function client(): User
    {
        return Auth::user();
    }

    #[Computed]
    public function today()
    {
        return $this->client->assignedWorkouts()->whereDate('scheduled_on', today())->withCount('items')->get();
    }

    #[Computed]
    public function upcoming()
    {
        return $this->client->assignedWorkouts()
            ->whereBetween('scheduled_on', [today()->addDay(), today()->addDays(14)])
            ->withCount('items')
            ->orderBy('scheduled_on')
            ->get();
    }

    #[Computed]
    public function recent()
    {
        return $this->client->assignedWorkouts()
            ->where('scheduled_on', '<', today())
            ->orderByDesc('scheduled_on')
            ->limit(10)
            ->get();
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Hey :name 👋', ['name' => str($this->client->name)->before(' ')]) }}</flux:heading>
        @if ($this->client->goal)
            <flux:text class="mt-1">{{ __('Working toward: :goal', ['goal' => $this->client->goal]) }}</flux:text>
        @endif
    </div>

    <section class="flex flex-col gap-3">
        <flux:heading size="lg">{{ __('Today') }}</flux:heading>
        @forelse ($this->today as $workout)
            <a href="{{ route('training.workout', $workout) }}" wire:navigate class="block">
                <flux:card class="flex items-center justify-between gap-4 hover:border-zinc-400 dark:hover:border-zinc-500">
                    <div>
                        <div class="text-lg font-semibold text-zinc-900 dark:text-white">{{ $workout->title }}</div>
                        <flux:text>{{ trans_choice(':count exercise|:count exercises', $workout->items_count) }}</flux:text>
                    </div>
                    @if ($workout->isCompleted())
                        <flux:badge color="green" icon="check">{{ __('Done') }}</flux:badge>
                    @else
                        <flux:button variant="primary" icon-trailing="arrow-right" as="div">{{ __('Start') }}</flux:button>
                    @endif
                </flux:card>
            </a>
        @empty
            <flux:card>
                <flux:text>{{ __('Rest day — nothing scheduled for today.') }}</flux:text>
            </flux:card>
        @endforelse
    </section>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="flex flex-col gap-3">
            <flux:heading size="lg">{{ __('Coming up') }}</flux:heading>
            <flux:card class="flex flex-col gap-3">
                @forelse ($this->upcoming as $workout)
                    <a href="{{ route('training.workout', $workout) }}" wire:navigate class="flex items-center justify-between gap-3 hover:underline">
                        <span class="font-medium text-zinc-900 dark:text-white">{{ $workout->title }}</span>
                        <flux:text size="sm">{{ $workout->scheduled_on->format('D, M j') }}</flux:text>
                    </a>
                @empty
                    <flux:text>{{ __('Your coach has not scheduled anything else yet.') }}</flux:text>
                @endforelse
            </flux:card>
        </section>

        <section class="flex flex-col gap-3">
            <flux:heading size="lg">{{ __('Recent') }}</flux:heading>
            <flux:card class="flex flex-col gap-3">
                @forelse ($this->recent as $workout)
                    <a href="{{ route('training.workout', $workout) }}" wire:navigate class="flex items-center justify-between gap-3">
                        <span class="font-medium text-zinc-900 hover:underline dark:text-white">{{ $workout->title }}</span>
                        <div class="flex items-center gap-2">
                            <flux:text size="sm">{{ $workout->scheduled_on->format('M j') }}</flux:text>
                            @if ($workout->isCompleted())
                                <flux:badge size="sm" color="green" icon="check">{{ __('Done') }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="zinc">{{ __('Missed') }}</flux:badge>
                            @endif
                        </div>
                    </a>
                @empty
                    <flux:text>{{ __('Your history will show up here.') }}</flux:text>
                @endforelse
            </flux:card>
        </section>
    </div>
</div>
