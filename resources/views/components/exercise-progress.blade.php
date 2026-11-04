@props([
    /** @var \Illuminate\Support\Collection $summary rows from ExerciseHistory::summary() */
    'summary',
    /** @var array|null $detail ExerciseHistory::forExercise() for the open exercise */
    'detail' => null,
    'metric' => 'heaviest',
    /** Coaches can switch the metric (it's saved as their choice); clients see the coach's. */
    'canChangeMetric' => false,
    'empty' => null,
])

@php
    $history = app(\App\Services\ExerciseHistory::class);

    // Points scaled into a box, oldest on the left. Values are kg (or kg × reps for volume).
    $line = function ($points, string $metric, int $width, int $height, int $pad = 2): array {
        $values = $points->pluck($metric)->values();
        $min = $values->min();
        $max = $values->max();
        $span = ($max - $min) ?: 1;
        $count = max($values->count() - 1, 1);

        return $values->map(fn ($v, $i) => [
            round($pad + ($width - 2 * $pad) * ($values->count() === 1 ? 0.5 : $i / $count), 1),
            round($height - $pad - ($height - 2 * $pad) * ($max === $min ? 0.5 : ($v - $min) / $span), 1),
        ])->all();
    };
@endphp

<div class="flex flex-col gap-6">
    @if ($canChangeMetric)
        <flux:radio.group wire:model.live="metric" variant="segmented" size="sm" :label="__('Show progress as')" data-test="history-metric">
            @foreach (\App\Services\ExerciseHistory::METRICS as $value => $label)
                <flux:radio :value="$value" :label="__($label)" />
            @endforeach
        </flux:radio.group>
    @else
        <flux:text size="sm">{{ __('Progress shown as :metric.', ['metric' => mb_strtolower(__(\App\Services\ExerciseHistory::METRICS[$metric]))]) }}</flux:text>
    @endif

    @if ($detail)
        @php($points = $detail['points'])
        @php($coords = $line($points, $metric, 600, 180, 12))
        <flux:card class="flex flex-col gap-4" data-test="exercise-detail">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <flux:heading size="lg">{{ $detail['exercise']->name }}</flux:heading>
                    <flux:text size="sm">{{ trans_choice(':count session|:count sessions', $detail['sessions']) }}</flux:text>
                </div>
                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="$set('exerciseId', null)">{{ __('Close') }}</flux:button>
            </div>

            <div class="grid grid-cols-3 gap-2">
                @foreach ([[__('First'), $detail['first']], [__('Best'), $detail['best']], [__('Latest'), $detail['latest']]] as [$label, $point])
                    <div class="rounded-lg bg-zinc-50 p-3 dark:bg-zinc-900">
                        <div class="text-xs font-medium uppercase text-zinc-500">{{ $label }}</div>
                        <div class="font-display text-xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ $history->format($point[$metric], $detail['unit']) }}</div>
                        <div class="text-xs text-zinc-500">{{ $point['date']->translatedFormat('j M Y') }}</div>
                    </div>
                @endforeach
            </div>

            @if ($points->count() > 1)
                <svg viewBox="0 0 600 180" class="h-44 w-full text-brand dark:text-lime" role="img"
                     aria-label="{{ __(':exercise from :first to :latest', ['exercise' => $detail['exercise']->name, 'first' => $history->format($detail['first'][$metric], $detail['unit']), 'latest' => $history->format($detail['latest'][$metric], $detail['unit'])]) }}">
                    <line x1="12" y1="168" x2="588" y2="168" class="stroke-zinc-200 dark:stroke-zinc-700" stroke-width="1" />
                    <polyline fill="none" stroke="currentColor" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"
                              points="{{ collect($coords)->map(fn ($c) => implode(',', $c))->implode(' ') }}" />
                    @foreach ($coords as $i => [$x, $y])
                        <circle cx="{{ $x }}" cy="{{ $y }}" r="3.5" fill="currentColor">
                            <title>{{ $points[$i]['date']->translatedFormat('j M Y') }}: {{ $history->format($points[$i][$metric], $detail['unit']) }}</title>
                        </circle>
                    @endforeach
                </svg>
            @endif

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-xs uppercase text-zinc-500">
                        <th class="py-1 text-start font-medium">{{ __('Date') }}</th>
                        <th class="py-1 text-start font-medium">{{ __('Sets') }}</th>
                        <th class="py-1 text-end font-medium">{{ __(\App\Services\ExerciseHistory::METRICS[$metric]) }}</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($points->reverse() as $point)
                            <tr class="border-t border-zinc-100 dark:border-zinc-700">
                                <td class="py-2 pe-3 whitespace-nowrap text-zinc-600 dark:text-zinc-300">{{ $point['date']->translatedFormat('j M Y') }}</td>
                                <td class="py-2 pe-3 text-zinc-900 dark:text-white">{{ $point['sets'] ?: '—' }}</td>
                                <td class="py-2 text-end tabular-nums text-zinc-900 dark:text-white">{{ $history->format($point[$metric], $detail['unit']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </flux:card>
    @endif

    @if ($summary->isEmpty())
        <flux:card class="py-10 text-center">
            <flux:text>{{ $empty ?? __('Progress shows up here once workouts with logged weights are completed.') }}</flux:text>
        </flux:card>
    @else
        <div class="flex flex-col divide-y divide-zinc-100 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
            @foreach ($summary as $row)
                @php($spark = $line($row['points'], $metric, 96, 28))
                <button type="button" wire:click="$set('exerciseId', {{ $row['exercise']->id }})" wire:key="progress-{{ $row['exercise']->id }}"
                        class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3 px-4 py-3 text-start hover:bg-zinc-50 sm:grid-cols-[minmax(0,1fr)_6rem_repeat(3,6.5rem)] dark:hover:bg-zinc-800"
                        data-test="progress-row">
                    <div class="min-w-0">
                        <div class="truncate font-medium text-zinc-900 dark:text-white">{{ $row['exercise']->name }}</div>
                        <div class="text-xs text-zinc-500">
                            {{ trans_choice(':count session|:count sessions', $row['sessions']) }}
                            @if ($row['change'] !== null && $row['change'] != 0)
                                · <span @class(['font-semibold', 'text-green-700 dark:text-green-400' => $row['change'] > 0, 'text-red-700 dark:text-red-400' => $row['change'] < 0])>{{ sprintf('%+d%%', $row['change']) }}</span> {{ __('since first') }}
                            @endif
                        </div>
                    </div>
                    <svg viewBox="0 0 96 28" class="hidden h-7 w-24 text-brand sm:block dark:text-lime" aria-hidden="true">
                        @if (count($spark) > 1)
                            <polyline fill="none" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round" stroke-linecap="round" points="{{ collect($spark)->map(fn ($c) => implode(',', $c))->implode(' ') }}" />
                        @else
                            <circle cx="{{ $spark[0][0] }}" cy="{{ $spark[0][1] }}" r="2" fill="currentColor" />
                        @endif
                    </svg>
                    @foreach ([[__('First'), $row['first']], [__('Best'), $row['best']], [__('Latest'), $row['latest']]] as [$label, $point])
                        <div @class(['text-end', 'hidden sm:block' => $label !== __('Latest')])>
                            <div class="text-[11px] uppercase text-zinc-500">{{ $label }}</div>
                            <div class="font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $history->format($point[$metric], $row['unit']) }}</div>
                        </div>
                    @endforeach
                </button>
            @endforeach
        </div>
    @endif
</div>
