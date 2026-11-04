<?php

use App\Models\Habit;
use App\Models\User;
use Flux\Flux;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * A client's habits. The client ticks them off (today or yesterday) and can add their own;
 * their coach adds habits for them and sees how each week is going.
 */
new class extends Component {
    public User $client;

    /** Which day the client is logging: today or yesterday (habits are often logged the next morning). */
    public string $day = 'today';

    /** @var array<int, string> values being typed for number habits, keyed by habit id */
    public array $values = [];

    public string $name = '';

    public string $type = 'check';

    public string $target = '';

    public string $unit = '';

    public int $daysPerWeek = 7;

    public function mount(User $client): void
    {
        $this->client = $client;
        $this->authorizeViewer();
        $this->loadValues();
    }

    public function hydrate(): void
    {
        $this->authorizeViewer();
    }

    protected function authorizeViewer(): void
    {
        $viewer = Auth::user();

        abort_unless($viewer && ($viewer->id === $this->client->id || $viewer->managesClient($this->client)), 403);
    }

    public function viewerIsClient(): bool
    {
        return Auth::id() === $this->client->id;
    }

    public function date(): CarbonInterface
    {
        return $this->day === 'yesterday' ? today()->subDay() : today();
    }

    #[Computed]
    public function habits()
    {
        return $this->client->habits()->active()
            ->with(['logs' => fn ($q) => $q->where('logged_on', '>=', today()->subDays(13)->startOfWeek()->toDateString()), 'createdBy:id,name'])
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    protected function loadValues(): void
    {
        $date = $this->date()->toDateString();

        $this->values = $this->habits
            ->filter(fn (Habit $h) => $h->isNumber())
            ->mapWithKeys(function (Habit $h) use ($date) {
                $value = $h->logs->first(fn ($l) => $l->logged_on->toDateString() === $date)?->value;

                return [$h->id => $value === null ? '' : rtrim(rtrim((string) $value, '0'), '.')];
            })
            ->all();
    }

    public function updatedDay(): void
    {
        $this->day = $this->day === 'yesterday' ? 'yesterday' : 'today';
        unset($this->habits);
        $this->loadValues();
    }

    protected function findHabit(int $id): Habit
    {
        return $this->client->habits()->active()->findOrFail($id);
    }

    public function toggle(int $id): void
    {
        abort_unless($this->viewerIsClient(), 403);

        $habit = $this->findHabit($id);
        $existing = $habit->logs()->where('logged_on', $this->date()->toDateString())->first();
        $habit->log($this->date(), done: ! ($existing?->done ?? false));

        unset($this->habits);
    }

    public function saveValue(int $id): void
    {
        abort_unless($this->viewerIsClient(), 403);

        $this->validate(["values.$id" => ['nullable', 'numeric', 'min:0', 'max:1000000']], attributes: ["values.$id" => __('value')]);

        $habit = $this->findHabit($id);
        $raw = $this->values[$id] ?? '';

        $raw === '' || $raw === null
            ? $habit->logs()->where('logged_on', $this->date()->toDateString())->delete()
            : $habit->log($this->date(), (float) $raw);

        unset($this->habits);
    }

    public function addHabit(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'type' => ['required', Rule::in(array_keys(Habit::TYPES))],
            'target' => [Rule::requiredIf($this->type === 'number'), 'nullable', 'numeric', 'gt:0', 'max:1000000'],
            'unit' => ['nullable', 'string', 'max:20'],
            'daysPerWeek' => ['required', 'integer', 'between:1,7'],
        ], attributes: ['daysPerWeek' => __('days per week')]);

        if ($this->client->habits()->active()->count() >= 12) {
            $this->addError('name', __('That’s plenty of habits. Stop one before adding another.'));

            return;
        }

        $this->client->habits()->create([
            'created_by_id' => Auth::id(),
            'name' => $this->name,
            'type' => $this->type,
            'target' => $this->type === 'number' ? $this->target : null,
            'unit' => $this->type === 'number' && $this->unit !== '' ? $this->unit : null,
            'days_per_week' => $this->daysPerWeek,
            'position' => (int) $this->client->habits()->max('position') + 1,
        ]);

        $this->reset('name', 'type', 'target', 'unit', 'daysPerWeek');
        unset($this->habits);
        $this->loadValues();
        Flux::modal('add-habit-'.$this->client->id)->close();
        Flux::toast(variant: 'success', text: __('Habit added.'));
    }

    /**
     * Stop tracking a habit. Its history is kept. Clients can only stop the habits they added.
     */
    public function endHabit(int $id): void
    {
        $habit = $this->findHabit($id);

        abort_if($this->viewerIsClient() && ! $habit->isClientOwn(), 403);

        $habit->update(['ended_at' => now()]);
        unset($this->habits);
    }
}; ?>

@php($date = $this->date())
<div class="flex flex-col gap-3" data-test="habits">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:heading size="lg">{{ __('Habits') }}</flux:heading>
        <div class="flex items-center gap-2">
            @if ($this->viewerIsClient() && $this->habits->isNotEmpty())
                <flux:radio.group wire:model.live="day" variant="segmented" size="sm" :aria-label="__('Day')">
                    <flux:radio value="today" :label="__('Today')" />
                    <flux:radio value="yesterday" :label="__('Yesterday')" />
                </flux:radio.group>
            @endif
            <flux:modal.trigger :name="'add-habit-'.$client->id">
                <flux:button size="sm" icon="plus" data-test="add-habit">{{ __('Add') }}</flux:button>
            </flux:modal.trigger>
        </div>
    </div>

    <flux:card class="flex flex-col divide-y divide-zinc-100 p-0 dark:divide-zinc-700">
        @forelse ($this->habits as $habit)
            @php($log = $habit->logs->first(fn ($l) => $l->logged_on->isSameDay($date)))
            @php($week = $habit->weekProgress($date))
            <div class="flex items-center gap-3 px-4 py-3" wire:key="habit-{{ $habit->id }}">
                @if ($this->viewerIsClient() && ! $habit->isNumber())
                    <button type="button" wire:click="toggle({{ $habit->id }})" @class([
                        'flex size-8 shrink-0 items-center justify-center rounded-full border-2 transition',
                        'border-brand bg-brand text-white dark:border-lime dark:bg-lime dark:text-ink' => $log?->done,
                        'border-zinc-300 text-transparent hover:border-zinc-500 dark:border-zinc-600' => ! $log?->done,
                    ]) aria-pressed="{{ $log?->done ? 'true' : 'false' }}" aria-label="{{ __('Done: :habit', ['habit' => $habit->name]) }}" data-test="toggle-habit">
                        <flux:icon.check variant="micro" />
                    </button>
                @endif

                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <span class="truncate font-medium text-zinc-900 dark:text-white">{{ $habit->name }}</span>
                        @if (! $this->viewerIsClient() && $habit->isClientOwn())
                            <flux:badge size="sm" color="zinc">{{ __('Their own') }}</flux:badge>
                        @endif
                    </div>
                    <div class="text-xs text-zinc-500">
                        {{ $habit->describe() }}
                        · <span @class(['font-semibold text-green-700 dark:text-green-400' => $week['met']])>{{ __(':done of :goal this week', ['done' => $week['done'], 'goal' => $week['goal']]) }}</span>
                    </div>
                </div>

                @if ($this->viewerIsClient() && $habit->isNumber())
                    <form wire:submit="saveValue({{ $habit->id }})" class="flex shrink-0 items-center gap-1">
                        <flux:input size="sm" wire:model="values.{{ $habit->id }}" inputmode="decimal" class="w-24" :placeholder="$habit->formatValue($habit->target)" :aria-label="__(':habit for :day', ['habit' => $habit->name, 'day' => $date->translatedFormat('l')])" />
                        <flux:button size="sm" type="submit" :variant="$log?->done ? 'primary' : 'outline'" icon="check" :aria-label="__('Save')" data-test="save-habit-value" />
                    </form>
                @endif

                @unless ($this->viewerIsClient())
                    {{-- Last 7 days, oldest first. --}}
                    <div class="flex shrink-0 gap-1" aria-label="{{ __('Last 7 days') }}">
                        @foreach (range(6, 0) as $ago)
                            @php($d = today()->subDays($ago))
                            @php($l = $habit->logs->first(fn ($x) => $x->logged_on->isSameDay($d)))
                            <span title="{{ $d->translatedFormat('D j M') }}{{ $l?->value !== null ? ': '.$habit->formatValue($l->value) : '' }}" @class([
                                'size-3 rounded-full',
                                'bg-brand dark:bg-lime' => $l?->done,
                                'bg-amber-300 dark:bg-amber-500/60' => $l && ! $l->done,
                                'bg-zinc-200 dark:bg-zinc-700' => ! $l,
                            ])></span>
                        @endforeach
                    </div>
                @endunless

                @if (! $this->viewerIsClient() || $habit->isClientOwn())
                    <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="endHabit({{ $habit->id }})" wire:confirm="{{ __('Stop tracking :habit? Its history is kept.', ['habit' => $habit->name]) }}" :aria-label="__('Stop tracking :habit', ['habit' => $habit->name])" />
                @endif
            </div>
            @error("values.$habit->id")
                <div class="px-4 pb-2"><flux:error :name="'values.'.$habit->id" /></div>
            @enderror
        @empty
            <div class="px-4 py-6">
                <flux:text>{{ $this->viewerIsClient()
                    ? __('No habits yet. Your coach may add some, or add your own: a step target, a daily walk, water.')
                    : __('No habits yet. Add a step target, a walk or a water goal for :name to tick off each day.', ['name' => str($client->name)->before(' ')]) }}</flux:text>
            </div>
        @endforelse
    </flux:card>

    <flux:modal :name="'add-habit-'.$client->id" class="md:w-96">
        <form wire:submit="addHabit" class="flex flex-col gap-5">
            <flux:heading size="lg">{{ $this->viewerIsClient() ? __('Add a habit') : __('Add a habit for :name', ['name' => str($client->name)->before(' ')]) }}</flux:heading>

            <flux:input wire:model="name" :label="__('Habit')" :placeholder="__('e.g. Steps, 30-minute walk, Water')" required />

            <flux:radio.group wire:model.live="type" variant="segmented" :label="__('How it’s tracked')">
                @foreach (\App\Models\Habit::TYPES as $value => $label)
                    <flux:radio :value="$value" :label="__($label)" />
                @endforeach
            </flux:radio.group>

            @if ($type === 'number')
                <div class="grid grid-cols-2 gap-3">
                    <flux:input wire:model="target" inputmode="decimal" :label="__('Daily target')" placeholder="8000" />
                    <flux:input wire:model="unit" :label="__('Unit')" :placeholder="__('steps')" />
                </div>
            @endif

            <flux:select wire:model="daysPerWeek" :label="__('How often')">
                <flux:select.option value="7">{{ __('Every day') }}</flux:select.option>
                @foreach (range(6, 1) as $n)
                    <flux:select.option :value="$n">{{ trans_choice(':count day a week|:count days a week', $n) }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-habit">{{ __('Add habit') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
