<?php

use App\Models\User;
use App\Models\Workout;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Overview')] class extends Component {
    #[Computed]
    public function coach(): User
    {
        return Auth::user();
    }

    #[Computed]
    public function stats(): array
    {
        $workouts = Workout::query()->where('coach_id', $this->coach->id)->whereNotNull('client_id');

        return [
            'clients' => $this->coach->clients()->count(),
            'completed_this_week' => (clone $workouts)->whereBetween('completed_at', [now()->startOfWeek(), now()])->count(),
            'scheduled_today' => (clone $workouts)->whereDate('scheduled_on', today())->count(),
            'missed_last_7_days' => (clone $workouts)->whereNull('completed_at')
                ->whereBetween('scheduled_on', [today()->subDays(7), today()->subDay()])->count(),
        ];
    }

    /**
     * Clients who missed a workout recently or have nothing on their calendar.
     */
    #[Computed]
    public function needsAttention()
    {
        return $this->coach->clients()
            ->withCount([
                'assignedWorkouts as missed_count' => fn ($q) => $q->whereNull('completed_at')
                    ->whereBetween('scheduled_on', [today()->subDays(7), today()->subDay()]),
                'assignedWorkouts as upcoming_count' => fn ($q) => $q->where('scheduled_on', '>=', today()),
            ])
            ->orderBy('name')
            ->get()
            ->filter(fn (User $client) => $client->missed_count > 0 || $client->upcoming_count === 0);
    }

    #[Computed]
    public function recentActivity()
    {
        return Workout::query()
            ->where('coach_id', $this->coach->id)
            ->whereNotNull('completed_at')
            ->with('client')
            ->latest('completed_at')
            ->limit(8)
            ->get();
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Good to see you, :name', ['name' => str($this->coach->name)->before(' ')]) }}</flux:heading>
        <flux:text class="mt-1">{{ __("Here's how your clients are doing.") }}</flux:text>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['label' => __('Active clients'), 'value' => $this->stats['clients']],
            ['label' => __('Scheduled today'), 'value' => $this->stats['scheduled_today']],
            ['label' => __('Completed this week'), 'value' => $this->stats['completed_this_week']],
            ['label' => __('Missed (last 7 days)'), 'value' => $this->stats['missed_last_7_days']],
        ] as $stat)
            <flux:card class="flex flex-col gap-1">
                <flux:text>{{ $stat['label'] }}</flux:text>
                <span class="text-3xl font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $stat['value'] }}</span>
            </flux:card>
        @endforeach
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <flux:card class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Needs attention') }}</flux:heading>

            @forelse ($this->needsAttention as $client)
                <a href="{{ route('coach.clients.show', $client) }}" wire:navigate class="flex items-center justify-between gap-3 rounded-lg p-2 -m-2 hover:bg-zinc-50 dark:hover:bg-zinc-700/50">
                    <div class="flex items-center gap-3">
                        <flux:avatar size="sm" :name="$client->name" :initials="$client->initials()" />
                        <span class="font-medium text-zinc-900 dark:text-white">{{ $client->name }}</span>
                    </div>
                    <div class="flex gap-2">
                        @if ($client->missed_count > 0)
                            <flux:badge color="red" size="sm">{{ trans_choice(':count missed|:count missed', $client->missed_count) }}</flux:badge>
                        @endif
                        @if ($client->upcoming_count === 0)
                            <flux:badge color="amber" size="sm">{{ __('Nothing scheduled') }}</flux:badge>
                        @endif
                    </div>
                </a>
            @empty
                <flux:text>{{ __('Everyone is on track. Nice work.') }}</flux:text>
            @endforelse
        </flux:card>

        <flux:card class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Recent activity') }}</flux:heading>

            @forelse ($this->recentActivity as $workout)
                <div class="flex flex-col gap-1">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm text-zinc-900 dark:text-white">
                            <a href="{{ route('coach.clients.show', $workout->client_id) }}" wire:navigate class="font-medium hover:underline">{{ $workout->client->name }}</a>
                            {{ __('completed') }}
                            <a href="{{ route('coach.workouts.edit', $workout) }}" wire:navigate class="font-medium hover:underline">{{ $workout->title }}</a>
                        </span>
                        <flux:text size="sm" class="shrink-0">{{ $workout->completed_at->diffForHumans() }}</flux:text>
                    </div>
                    @if ($workout->client_feedback)
                        <flux:text size="sm" class="italic">“{{ $workout->client_feedback }}”</flux:text>
                    @endif
                </div>
            @empty
                <flux:text>{{ __('Completed workouts will show up here.') }}</flux:text>
            @endforelse
        </flux:card>
    </div>
</div>
