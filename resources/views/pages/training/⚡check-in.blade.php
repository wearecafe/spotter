<?php

use App\Models\CheckIn;
use App\Models\User;
use App\Services\InboxFeed;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Weekly check-in')] class extends Component {
    use WithFileUploads;

    public string $weight = '';

    public string $weightUnit = 'kg';

    public string $sleepHours = '';

    public string $avgSteps = '';

    public ?int $energy = null;

    public ?int $stress = null;

    public string $notes = '';

    /** @var array<int, mixed> */
    public array $photos = [];

    public bool $sent = false;

    public function mount(): void
    {
        $existing = $this->existing;
        $last = $this->client->checkIns()->latest('week_of')->first();

        $this->weightUnit = $last?->weight_unit ?? 'kg';

        if ($existing) {
            $this->weight = (string) $existing->weight;
            $this->weightUnit = $existing->weight_unit;
            $this->sleepHours = (string) $existing->sleep_hours;
            $this->avgSteps = (string) $existing->avg_steps;
            $this->energy = $existing->energy;
            $this->stress = $existing->stress;
            $this->notes = (string) $existing->notes;
        }
    }

    #[Computed]
    public function client(): User
    {
        return Auth::user();
    }

    #[Computed]
    public function existing(): ?CheckIn
    {
        return $this->client->checkIns()->whereDate('week_of', today()->startOfWeek())->first();
    }

    /**
     * @return array{done: int, scheduled: int}
     */
    #[Computed]
    public function sessions(): array
    {
        $week = $this->client->assignedWorkouts()->whereBetween('scheduled_on', [today()->startOfWeek(), today()->endOfWeek()]);

        return ['done' => (clone $week)->whereNotNull('completed_at')->count(), 'scheduled' => (clone $week)->count()];
    }

    public function submit(InboxFeed $feed): void
    {
        $this->validate([
            'weight' => ['nullable', 'numeric', 'min:20', 'max:700'],
            'weightUnit' => ['required', 'in:kg,lb'],
            'sleepHours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'avgSteps' => ['nullable', 'integer', 'min:0', 'max:200000'],
            'energy' => ['required', 'integer', 'between:1,5'],
            'stress' => ['required', 'integer', 'between:1,5'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'photos' => ['array', 'max:3'],
            'photos.*' => ['image', 'max:12288'],
        ], attributes: ['sleepHours' => __('sleep'), 'avgSteps' => __('steps')]);

        $paths = collect($this->photos)
            ->map(fn ($photo) => $photo->store('check-ins/'.$this->client->id, config('filesystems.media_disk')))
            ->all();

        $isNew = $this->existing === null;

        $checkIn = CheckIn::updateOrCreate(
            ['client_id' => $this->client->id, 'week_of' => today()->startOfWeek()->toDateString()],
            [
                'weight' => $this->weight === '' ? null : $this->weight,
                'weight_unit' => $this->weightUnit,
                'sleep_hours' => $this->sleepHours === '' ? null : $this->sleepHours,
                'avg_steps' => $this->avgSteps === '' ? null : (int) $this->avgSteps,
                'energy' => $this->energy,
                'stress' => $this->stress,
                'notes' => $this->notes ?: null,
                'photos' => array_merge($this->existing?->photos ?? [], $paths),
            ],
        );

        if ($isNew) {
            $feed->checkInSubmitted($checkIn);
        }

        $this->photos = [];
        $this->sent = true;
        unset($this->existing);
    }
}; ?>

<div class="mx-auto flex w-full max-w-xl flex-col gap-6">
    @if ($sent)
        <flux:card class="flex flex-col items-center gap-3 py-12 text-center">
            <span class="flex size-14 items-center justify-center rounded-full bg-lime text-2xl font-bold text-ink" aria-hidden="true">✓</span>
            <flux:heading size="xl" level="1">{{ __('Check-in sent') }}</flux:heading>
            <flux:text>{{ __(':coach usually replies within a day.', ['coach' => str($this->client->coach?->name)->before(' ')]) }}</flux:text>
            <div class="mt-2 flex gap-2">
                <flux:button :href="route('training.index')" wire:navigate>{{ __('Back to training') }}</flux:button>
                <flux:button variant="ghost" wire:click="$set('sent', false)">{{ __('Edit check-in') }}</flux:button>
            </div>
        </flux:card>
    @else
        <div>
            <flux:heading size="xl" level="1">{{ __('Weekly check-in') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Week of :date · takes about 2 minutes', ['date' => today()->startOfWeek()->translatedFormat('j F')]) }}</flux:text>
        </div>

        <form wire:submit="submit" class="flex flex-col gap-6">
            <flux:card class="flex flex-col gap-4">
                <flux:heading>{{ __('This week') }}</flux:heading>
                <div class="grid grid-cols-3 gap-3 text-center">
                    <div class="rounded-lg bg-zinc-50 p-3 dark:bg-zinc-900">
                        <div class="font-display text-2xl font-extrabold tabular-nums">{{ $this->sessions['done'] }}/{{ $this->sessions['scheduled'] }}</div>
                        <flux:text size="sm">{{ __('sessions') }}</flux:text>
                    </div>
                    <flux:input wire:model="sleepHours" type="number" step="0.1" min="0" max="24" :label="__('Avg sleep (h)')" size="sm" />
                    <flux:input wire:model="avgSteps" type="number" min="0" :label="__('Avg steps')" size="sm" />
                </div>
                <flux:text size="sm">{{ __('Wearable sync is coming. For now, enter sleep and steps from your watch or phone.') }}</flux:text>
            </flux:card>

            <flux:card class="flex flex-col gap-5">
                <div class="flex items-end gap-3">
                    <flux:input wire:model="weight" type="number" step="0.1" :label="__('Body weight')" class="flex-1" inputmode="decimal" />
                    <flux:radio.group wire:model="weightUnit" variant="segmented" :aria-label="__('Unit')">
                        <flux:radio value="kg" label="kg" />
                        <flux:radio value="lb" label="lb" />
                    </flux:radio.group>
                </div>

                @foreach (['energy' => __('Energy'), 'stress' => __('Stress')] as $field => $label)
                    <flux:field>
                        <flux:label>{{ $label }}</flux:label>
                        <div class="flex gap-2" role="radiogroup" aria-label="{{ $label }}">
                            @foreach (range(1, 5) as $n)
                                <button type="button" role="radio" aria-checked="{{ $this->{$field} === $n ? 'true' : 'false' }}"
                                    wire:click="$set('{{ $field }}', {{ $n }})"
                                    @class([
                                        'h-11 flex-1 rounded-lg border text-base font-semibold transition',
                                        'border-ink bg-ink text-white dark:border-lime dark:bg-lime dark:text-ink' => $this->{$field} === $n,
                                        'border-zinc-300 bg-white text-zinc-800 hover:border-zinc-500 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100' => $this->{$field} !== $n,
                                    ])>{{ $n }}</button>
                            @endforeach
                        </div>
                        <div class="flex justify-between text-xs text-zinc-500"><span>{{ __('Low') }}</span><span>{{ __('High') }}</span></div>
                        <flux:error :name="$field" />
                    </flux:field>
                @endforeach

                <flux:textarea wire:model="notes" :label="__('Anything else?')" :placeholder="__('Wins, struggles, sleep, travel, anything sore…')" rows="4" />
            </flux:card>

            <flux:card class="flex flex-col gap-3">
                <flux:heading>{{ __('Progress photos (optional)') }}</flux:heading>
                <flux:text size="sm">{{ __('Only you and your coach can see these.') }}</flux:text>
                <flux:input type="file" wire:model="photos" multiple accept="image/*" :aria-label="__('Progress photos')" />
                <div wire:loading wire:target="photos"><flux:text size="sm">{{ __('Uploading…') }}</flux:text></div>
                @if ($this->existing?->photos)
                    <flux:text size="sm">{{ trans_choice(':count photo already sent this week|:count photos already sent this week', count($this->existing->photos)) }}</flux:text>
                @endif
                <flux:error name="photos.*" />
            </flux:card>

            <flux:button type="submit" variant="primary" class="w-full" data-test="send-check-in">{{ $this->existing ? __('Update check-in') : __('Send check-in') }}</flux:button>
        </form>
    @endif
</div>
