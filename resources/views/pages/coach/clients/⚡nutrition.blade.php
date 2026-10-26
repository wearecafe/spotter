<?php

use App\Models\User;
use App\Services\NutritionSummary;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Nutrition')] class extends Component {
    public User $client;

    public string $calories = '';

    public string $protein = '';

    public string $carbs = '';

    public string $fat = '';

    /**
     * Re-checked on every action: access can change while the page is open (reassignment, removal).
     */
    public function hydrate(): void
    {
        Gate::authorize('manage-client', $this->client);
    }

    public function mount(User $client): void
    {
        Gate::authorize('manage-client', $client);

        $this->client = $client;
        $this->calories = (string) $client->calorie_target;
        $this->protein = (string) $client->protein_target;
        $this->carbs = (string) $client->carbs_target;
        $this->fat = (string) $client->fat_target;
    }

    #[Computed]
    public function week(): array
    {
        return app(NutritionSummary::class)->forDays($this->client, 7);
    }

    #[Computed]
    public function month(): array
    {
        return app(NutritionSummary::class)->forDays($this->client, 30);
    }

    #[Computed]
    public function logs()
    {
        return $this->client->nutritionLogs()->where('logged_on', '>=', today()->subDays(29)->toDateString())->orderByDesc('logged_on')->get();
    }

    /**
     * Calories implied by the macros (4/4/9), to sanity-check targets.
     */
    public function macroCalories(): ?int
    {
        if ($this->protein === '' && $this->carbs === '' && $this->fat === '') {
            return null;
        }

        return (int) ((int) $this->protein * 4 + (int) $this->carbs * 4 + (int) $this->fat * 9);
    }

    public function saveTargets(): void
    {
        $this->validate([
            'calories' => ['nullable', 'integer', 'min:800', 'max:10000'],
            'protein' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'carbs' => ['nullable', 'integer', 'min:0', 'max:1500'],
            'fat' => ['nullable', 'integer', 'min:0', 'max:600'],
        ]);

        $this->client->update([
            'calorie_target' => $this->calories === '' ? null : (int) $this->calories,
            'protein_target' => $this->protein === '' ? null : (int) $this->protein,
            'carbs_target' => $this->carbs === '' ? null : (int) $this->carbs,
            'fat_target' => $this->fat === '' ? null : (int) $this->fat,
        ]);

        unset($this->week, $this->month);
        Flux::toast(variant: 'success', text: __('Targets saved. :name sees them in their app.', ['name' => str($this->client->name)->before(' ')]));
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <flux:breadcrumbs>
            <flux:breadcrumbs.item :href="route('coach.clients.index')" wire:navigate>{{ __('Clients') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item :href="route('coach.clients.show', $client)" wire:navigate>{{ $client->name }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ __('Nutrition') }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>
        <flux:heading size="xl" level="1" class="mt-2">{{ __('Nutrition') }}</flux:heading>
    </div>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="flex min-w-0 flex-col gap-6">
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach ([[__('Last 7 days'), $this->week], [__('Last 30 days'), $this->month]] as [$label, $s])
                    <flux:card class="flex flex-col gap-3">
                        <div class="flex items-center justify-between">
                            <flux:heading>{{ $label }}</flux:heading>
                            <flux:text size="sm">{{ __(':logged of :days days logged', ['logged' => $s['logged_days'], 'days' => $s['days']]) }}</flux:text>
                        </div>
                        <div class="grid grid-cols-4 gap-2">
                            @foreach (['calories' => 'kcal', 'protein' => 'P', 'carbs' => 'C', 'fat' => 'F'] as $field => $short)
                                @php($avg = $s['averages'][$field])
                                @php($target = $s['targets'][$field])
                                <div>
                                    <div class="text-xs font-medium uppercase text-zinc-500">{{ $short }}</div>
                                    <div @class(['font-display text-lg font-bold tabular-nums', 'text-amber-600' => $avg !== null && $target && abs($avg - $target) > $target * 0.1])>{{ $avg !== null ? number_format($avg) : '—' }}</div>
                                    @if ($target)<div class="text-xs tabular-nums text-zinc-500">/ {{ number_format($target) }}</div>@endif
                                </div>
                            @endforeach
                        </div>
                        <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-zinc-600 dark:text-zinc-300">
                            @if ($s['on_target_days'] !== null)
                                <span>{{ trans_choice(':count day within 10% of calories|:count days within 10% of calories', $s['on_target_days']) }}</span>
                            @endif
                            @if ($s['weight_change'] !== null)
                                <span>{{ __('Weight :change :unit', ['change' => ($s['weight_change'] > 0 ? '+' : '').$s['weight_change'], 'unit' => $s['weight_unit']]) }}</span>
                            @endif
                        </div>
                    </flux:card>
                @endforeach
            </div>

            <flux:card class="flex flex-col gap-3 p-0!">
                <flux:heading class="px-5 pt-5">{{ __('Daily log') }}</flux:heading>
                @if ($this->logs->isEmpty())
                    <flux:text class="px-5 pb-5">{{ __(':name hasn’t logged nutrition yet. They can log days or import from MacroFactor, MyFitnessPal or Lose It! in their app.', ['name' => str($client->name)->before(' ')]) }}</flux:text>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[36rem] text-sm">
                            <thead><tr class="text-xs uppercase text-zinc-500">
                                <th class="px-5 py-2 text-start font-medium">{{ __('Day') }}</th>
                                <th class="px-2 py-2 text-end font-medium">{{ __('Weight') }}</th>
                                <th class="px-2 py-2 text-end font-medium">kcal</th>
                                <th class="px-2 py-2 text-end font-medium">{{ __('Protein') }}</th>
                                <th class="px-2 py-2 text-end font-medium">{{ __('Carbs') }}</th>
                                <th class="px-2 py-2 text-end font-medium">{{ __('Fat') }}</th>
                                <th class="px-5 py-2 text-end font-medium">{{ __('Source') }}</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($this->logs as $log)
                                    @php($off = $client->calorie_target && $log->calories !== null && abs($log->calories - $client->calorie_target) > $client->calorie_target * 0.1)
                                    <tr class="border-t border-zinc-100 tabular-nums dark:border-zinc-700">
                                        <td class="px-5 py-2">{{ $log->logged_on->translatedFormat('D j M') }}</td>
                                        <td class="px-2 py-2 text-end">{{ $log->weight ? $log->weight.' '.$log->weight_unit : '—' }}</td>
                                        <td @class(['px-2 py-2 text-end', 'font-semibold text-amber-600' => $off])>{{ $log->calories !== null ? number_format($log->calories) : '—' }}</td>
                                        <td class="px-2 py-2 text-end">{{ $log->protein ?? '—' }}</td>
                                        <td class="px-2 py-2 text-end">{{ $log->carbs ?? '—' }}</td>
                                        <td class="px-2 py-2 text-end">{{ $log->fat ?? '—' }}</td>
                                        <td class="px-5 py-2 text-end text-zinc-500">{{ $log->sourceLabel() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </flux:card>
        </div>

        <flux:card class="self-start">
            <form wire:submit="saveTargets" class="flex flex-col gap-4">
                <flux:heading>{{ __('Daily targets') }}</flux:heading>
                <flux:input wire:model.live.debounce.400ms="calories" type="number" :label="__('Calories')" />
                <div class="grid grid-cols-3 gap-3">
                    <flux:input wire:model.live.debounce.400ms="protein" type="number" :label="__('Protein g')" />
                    <flux:input wire:model.live.debounce.400ms="carbs" type="number" :label="__('Carbs g')" />
                    <flux:input wire:model.live.debounce.400ms="fat" type="number" :label="__('Fat g')" />
                </div>
                @if (($fromMacros = $this->macroCalories()) !== null)
                    <flux:text size="sm" @class(['text-amber-700!' => $calories !== '' && abs($fromMacros - (int) $calories) > 100])>
                        {{ __('Macros add up to :kcal kcal.', ['kcal' => number_format($fromMacros)]) }}
                    </flux:text>
                @endif
                <flux:button type="submit" variant="primary" class="self-start" data-test="save-targets">{{ __('Save targets') }}</flux:button>
            </form>
        </flux:card>
    </div>
</div>
