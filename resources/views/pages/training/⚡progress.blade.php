<?php

use App\Models\User;
use App\Services\ExerciseHistory;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('My progress')] class extends Component {
    #[Url(as: 'exercise')]
    public ?int $exerciseId = null;

    #[Computed]
    public function client(): User
    {
        return Auth::user();
    }

    /**
     * Clients see progress the way their coach measures it.
     */
    #[Computed]
    public function metric(): string
    {
        return app(ExerciseHistory::class)->metric($this->client->coach?->history_metric);
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

<div class="mx-auto flex w-full max-w-3xl flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('My progress') }}</flux:heading>
        <flux:text class="mt-1">{{ __('How each lift has moved since you started. Tap one to see every session.') }}</flux:text>
    </div>

    <x-exercise-progress :summary="$this->summary" :detail="$this->detail" :metric="$this->metric"
        :empty="__('Finish a workout with your weights logged and your progress starts here.')" />
</div>
