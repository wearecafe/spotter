<?php

use App\Models\User;
use App\Services\ExerciseHistory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Progress')] class extends Component {
    public User $client;

    #[Url(as: 'exercise')]
    public ?int $exerciseId = null;

    public string $metric = 'heaviest';

    public function hydrate(): void
    {
        Gate::authorize('manage-client', $this->client);
    }

    public function mount(User $client): void
    {
        Gate::authorize('manage-client', $client);

        $this->client = $client;
        $this->metric = app(ExerciseHistory::class)->metric($client->coach?->history_metric);
    }

    /**
     * The metric is the coach's choice: it's what this client sees too.
     */
    public function updatedMetric(ExerciseHistory $history): void
    {
        $this->metric = $history->metric($this->metric);
        unset($this->summary, $this->detail);

        // Saved as the viewing coach's choice; a teammate covering a session doesn't change the client's coach's.
        Auth::user()->update(['history_metric' => $this->metric]);
    }

    #[Computed]
    public function summary()
    {
        return app(ExerciseHistory::class)->summary($this->client, $this->metric);
    }

    #[Computed]
    public function detail(): ?array
    {
        return $this->exerciseId ? app(ExerciseHistory::class)->forExercise($this->client, $this->exerciseId, $this->metric) : null;
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <flux:breadcrumbs>
            <flux:breadcrumbs.item :href="route('coach.clients.index')" wire:navigate>{{ __('Clients') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item :href="route('coach.clients.show', $client)" wire:navigate>{{ $client->name }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ __('Progress') }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>
        <flux:heading size="xl" level="1" class="mt-2">{{ __('Progress') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Every lift :name has logged, across all programs. Tap one for the full history.', ['name' => str($client->name)->before(' ')]) }}</flux:text>
    </div>

    <x-exercise-progress :summary="$this->summary" :detail="$this->detail" :metric="$metric" :can-change-metric="true" />
</div>
