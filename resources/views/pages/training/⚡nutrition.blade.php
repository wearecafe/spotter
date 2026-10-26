<?php

use App\Models\NutritionLog;
use App\Models\User;
use App\Services\NutritionImporter;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Nutrition')] class extends Component {
    use WithFileUploads;

    public string $date = '';

    public string $weight = '';

    public string $weightUnit = 'kg';

    public string $calories = '';

    public string $protein = '';

    public string $carbs = '';

    public string $fat = '';

    public string $importSource = 'macrofactor';

    public $importFile = null;

    public function mount(): void
    {
        $this->date = today()->toDateString();
        $this->weightUnit = $this->client->nutritionLogs()->whereNotNull('weight')->latest('logged_on')->value('weight_unit')
            ?? $this->client->checkIns()->latest('week_of')->value('weight_unit')
            ?? 'kg';
        $this->loadDay();
    }

    #[Computed]
    public function client(): User
    {
        return Auth::user();
    }

    #[Computed]
    public function recent()
    {
        return $this->client->nutritionLogs()->where('logged_on', '>=', today()->subDays(13)->toDateString())->orderByDesc('logged_on')->get();
    }

    public function updatedDate(): void
    {
        $this->loadDay();
    }

    protected function loadDay(): void
    {
        $log = $this->client->nutritionLogs()->whereDate('logged_on', $this->date)->first();

        $this->weight = (string) ($log?->weight ?? '');
        $this->calories = (string) ($log?->calories ?? '');
        $this->protein = (string) ($log?->protein ?? '');
        $this->carbs = (string) ($log?->carbs ?? '');
        $this->fat = (string) ($log?->fat ?? '');

        if ($log?->weight !== null) {
            $this->weightUnit = $log->weight_unit;
        }
    }

    public function save(): void
    {
        $this->validate([
            'date' => ['required', 'date', 'before_or_equal:today', 'after:'.today()->subYear()->toDateString()],
            'weight' => ['nullable', 'numeric', 'min:20', 'max:700'],
            'weightUnit' => ['required', 'in:kg,lb'],
            'calories' => ['nullable', 'integer', 'min:0', 'max:20000'],
            'protein' => ['nullable', 'integer', 'min:0', 'max:2000'],
            'carbs' => ['nullable', 'integer', 'min:0', 'max:3000'],
            'fat' => ['nullable', 'integer', 'min:0', 'max:1500'],
        ]);

        $values = [
            'weight' => $this->weight === '' ? null : $this->weight,
            'weight_unit' => $this->weightUnit,
            'calories' => $this->calories === '' ? null : (int) $this->calories,
            'protein' => $this->protein === '' ? null : (int) $this->protein,
            'carbs' => $this->carbs === '' ? null : (int) $this->carbs,
            'fat' => $this->fat === '' ? null : (int) $this->fat,
        ];

        if (collect($values)->except('weight_unit')->filter(fn ($v) => $v !== null)->isEmpty()) {
            $this->addError('calories', __('Enter at least your weight or calories.'));

            return;
        }

        NutritionLog::updateOrCreate(['client_id' => $this->client->id, 'logged_on' => $this->date], $values + ['source' => 'manual']);

        unset($this->recent);
        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    public function import(NutritionImporter $importer): void
    {
        $this->validate([
            'importSource' => ['required', 'in:macrofactor,myfitnesspal,loseit,csv'],
            'weightUnit' => ['required', 'in:kg,lb'],
            'importFile' => ['required', 'file', 'mimes:csv,txt,zip', 'max:20480'],
        ], attributes: ['importFile' => __('file')]);

        try {
            $result = $importer->read($this->importFile->getRealPath(), $this->importFile->getClientOriginalName(), $this->importSource, $this->weightUnit);
        } catch (\Throwable) {
            $this->addError('importFile', __('That file couldn’t be read. Upload the CSV (or the .zip from MyFitnessPal) exactly as the app exported it.'));

            return;
        }

        if ($result['days'] === []) {
            $this->addError('importFile', __('No daily totals found. The file needs a Date column and at least Calories or Weight.'));

            return;
        }

        $saved = $importer->save($this->client, $result['days'], $result['source']);
        $dates = array_keys($result['days']);

        $this->importFile = null;
        unset($this->recent);
        $this->loadDay();

        Flux::toast(variant: 'success', heading: __('Imported :count days', ['count' => count($dates)]), text: __(':from to :to. :new new, :updated updated.', [
            'from' => \Illuminate\Support\Carbon::parse(end($dates))->translatedFormat('j M'),
            'to' => \Illuminate\Support\Carbon::parse(reset($dates))->translatedFormat('j M'),
            'new' => $saved['created'],
            'updated' => $saved['updated'],
        ]));
    }
}; ?>

<div class="mx-auto flex w-full max-w-2xl flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Nutrition') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Weight, calories and macros, one entry per day.') }}</flux:text>
    </div>

    @if ($this->client->hasNutritionTargets())
        <flux:card class="flex flex-col gap-3">
            <flux:heading>{{ __('Your daily targets') }}</flux:heading>
            <div class="grid grid-cols-4 gap-2 text-center">
                @foreach (['calorie_target' => [__('Calories'), 'kcal'], 'protein_target' => [__('Protein'), 'g'], 'carbs_target' => [__('Carbs'), 'g'], 'fat_target' => [__('Fat'), 'g']] as $field => [$label, $unit])
                    <div class="min-w-0">
                        <div class="truncate text-xs font-medium uppercase text-zinc-500">{{ $label }}</div>
                        <div class="font-display text-xl font-bold tabular-nums">{{ $this->client->{$field} ? number_format($this->client->{$field}) : '—' }}</div>
                        <div class="text-xs text-zinc-500">{{ $unit }}</div>
                    </div>
                @endforeach
            </div>
        </flux:card>
    @endif

    <flux:card>
        <form wire:submit="save" class="flex flex-col gap-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:heading class="whitespace-nowrap">{{ __('Log a day') }}</flux:heading>
                <flux:input wire:model.live="date" type="date" size="sm" class="w-44" :max="today()->toDateString()" :aria-label="__('Date')" />
            </div>
            <div class="flex items-end gap-3">
                <flux:input wire:model="weight" type="number" step="0.1" inputmode="decimal" :label="__('Body weight')" class="flex-1" />
                <flux:radio.group wire:model="weightUnit" variant="segmented" :aria-label="__('Unit')">
                    <flux:radio value="kg" label="kg" />
                    <flux:radio value="lb" label="lb" />
                </flux:radio.group>
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <flux:input wire:model="calories" type="number" inputmode="numeric" :label="__('Calories')" />
                <flux:input wire:model="protein" type="number" inputmode="numeric" :label="__('Protein (g)')" />
                <flux:input wire:model="carbs" type="number" inputmode="numeric" :label="__('Carbs (g)')" />
                <flux:input wire:model="fat" type="number" inputmode="numeric" :label="__('Fat (g)')" />
            </div>
            <flux:button type="submit" variant="primary" class="self-end" data-test="save-nutrition">{{ __('Save') }}</flux:button>
        </form>
    </flux:card>

    <flux:card class="flex flex-col gap-3 p-0!">
        <flux:heading class="px-5 pt-5">{{ __('Last 14 days') }}</flux:heading>
        @if ($this->recent->isEmpty())
            <flux:text class="px-5 pb-5">{{ __('Nothing logged yet.') }}</flux:text>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-xs uppercase text-zinc-500">
                        <th class="px-3 py-2 text-start font-medium sm:px-5">{{ __('Day') }}</th>
                        <th class="px-2 py-2 text-end font-medium">{{ __('Weight') }}</th>
                        <th class="px-2 py-2 text-end font-medium">kcal</th>
                        <th class="px-2 py-2 text-end font-medium">P</th>
                        <th class="px-2 py-2 text-end font-medium">C</th>
                        <th class="px-3 py-2 text-end font-medium sm:px-5">F</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($this->recent as $log)
                            <tr class="border-t border-zinc-100 tabular-nums dark:border-zinc-700" wire:key="n-{{ $log->id }}">
                                <td class="px-3 py-2 sm:px-5">
                                    <button type="button" wire:click="$set('date', '{{ $log->logged_on->toDateString() }}')" class="whitespace-nowrap text-start hover:underline">{{ $log->logged_on->translatedFormat('D j') }}</button>
                                    @if ($log->source !== 'manual')<span class="block text-[11px] text-zinc-500">{{ $log->sourceLabel() }}</span>@endif
                                </td>
                                <td class="whitespace-nowrap px-2 py-2 text-end">{{ $log->weight ? $log->weight.' '.$log->weight_unit : '—' }}</td>
                                <td class="px-2 py-2 text-end">{{ $log->calories !== null ? number_format($log->calories) : '—' }}</td>
                                <td class="px-2 py-2 text-end">{{ $log->protein ?? '—' }}</td>
                                <td class="px-2 py-2 text-end">{{ $log->carbs ?? '—' }}</td>
                                <td class="px-3 py-2 text-end sm:px-5">{{ $log->fat ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </flux:card>

    <flux:card class="flex flex-col gap-4">
        <div>
            <flux:heading>{{ __('Import from your tracking app') }}</flux:heading>
            <flux:text size="sm" class="mt-1">{{ __('Export your data from the app, then upload the file here. Days already in Spotter are updated, not duplicated.') }}</flux:text>
        </div>
        <form wire:submit="import" class="flex flex-col gap-4">
            <flux:select wire:model.live="importSource" :label="__('App')">
                <flux:select.option value="macrofactor">MacroFactor</flux:select.option>
                <flux:select.option value="myfitnesspal">MyFitnessPal</flux:select.option>
                <flux:select.option value="loseit">Lose It!</flux:select.option>
                <flux:select.option value="csv">{{ __('Other') }}</flux:select.option>
            </flux:select>
            <flux:text size="sm">
                {{ match ($importSource) {
                    'myfitnesspal' => __('Upload the .zip from MyFitnessPal’s data export, or the Nutrition Summary CSV inside it. MyFitnessPal may require Premium to export.'),
                    'loseit' => __('Upload the food log CSV from Lose It!’s data export.'),
                    'csv' => __('Any CSV with a Date column plus Calories, Protein, Carbs, Fat or Weight columns.'),
                    default => __('Upload the CSV from MacroFactor’s data export.'),
                } }}
            </flux:text>
            <flux:input type="file" wire:model="importFile" accept=".csv,.zip,text/csv" :aria-label="__('Export file')" />
            <div wire:loading wire:target="importFile,import"><flux:text size="sm">{{ __('Working…') }}</flux:text></div>
            <flux:button type="submit" class="self-start" data-test="import-nutrition">{{ __('Import') }}</flux:button>
        </form>
    </flux:card>
</div>
