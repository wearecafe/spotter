@props(['item'])

@php($subject = $item->subject)

@if ($subject instanceof \App\Models\CheckIn)
    @php($previous = $subject->previous())
    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
        @foreach ([
            [__('Weight'), $subject->weight ? $subject->weight.' '.$subject->weight_unit : '—', $previous?->weight && $subject->weight ? sprintf('%+.1f', $subject->weight - $previous->weight) : null],
            [__('Sleep'), $subject->sleep_hours ? $subject->sleep_hours.' h' : '—', null],
            [__('Energy'), $subject->energy ? $subject->energy.'/5' : '—', $previous?->energy && $subject->energy ? sprintf('%+d', $subject->energy - $previous->energy) : null],
            [__('Stress'), $subject->stress ? $subject->stress.'/5' : '—', $previous?->stress && $subject->stress ? sprintf('%+d', $subject->stress - $previous->stress) : null],
        ] as [$label, $value, $delta])
            <div class="rounded-lg bg-zinc-50 p-3 dark:bg-zinc-900">
                <div class="text-xs font-medium uppercase text-zinc-500">{{ $label }}</div>
                <div class="font-display text-xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ $value }}</div>
                @if ($delta !== null && ! in_array($delta, ['+0', '+0.0'], true))
                    <div class="text-xs tabular-nums text-zinc-500">{{ __(':delta vs last week', ['delta' => $delta]) }}</div>
                @endif
            </div>
        @endforeach
    </div>
    @if ($subject->notes)
        <blockquote class="border-s-4 border-zinc-200 ps-3 text-[15px] text-zinc-700 dark:border-zinc-600 dark:text-zinc-300">{{ $subject->notes }}</blockquote>
    @endif
    @if ($subject->photos)
        <div class="flex gap-2">
            @foreach ($subject->photos as $i => $path)
                <a href="{{ route('media.check-in-photo', [$subject, $i]) }}" target="_blank">
                    <img src="{{ route('media.check-in-photo', [$subject, $i]) }}" alt="{{ __('Progress photo :n', ['n' => $i + 1]) }}" class="size-24 rounded-lg object-cover" loading="lazy">
                </a>
            @endforeach
        </div>
    @endif
@elseif ($subject instanceof \App\Models\FormCheck)
    <video controls preload="metadata" playsinline class="aspect-video w-full rounded-lg bg-black" src="{{ route('media.form-check', $subject) }}"></video>
    @if ($subject->note)
        <blockquote class="border-s-4 border-zinc-200 ps-3 text-[15px] text-zinc-700 dark:border-zinc-600 dark:text-zinc-300">{{ $subject->note }}</blockquote>
    @endif
@elseif ($subject instanceof \App\Models\Workout)
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-start text-xs uppercase text-zinc-500">
                <th class="py-1 text-start font-medium">{{ __('Exercise') }}</th>
                <th class="py-1 text-start font-medium">{{ __('Planned') }}</th>
                <th class="py-1 text-start font-medium">{{ __('Logged') }}</th>
            </tr></thead>
            <tbody>
                @foreach ($subject->items as $row)
                    <tr class="border-t border-zinc-100 dark:border-zinc-700">
                        <td class="py-2 pe-3 font-medium text-zinc-900 dark:text-white">{{ $row->exercise->name }}</td>
                        <td class="py-2 pe-3 text-zinc-600 dark:text-zinc-300">{{ $row->prescription() }}</td>
                        <td class="py-2 tabular-nums text-zinc-600 dark:text-zinc-300">
                            {{ collect($row->logged_sets ?? [])->filter(fn ($s) => $s['done'] ?? false)->map(fn ($s) => ($s['reps'] ?? '—').(($s['load'] ?? null) ? ' @ '.$s['load'] : '').(($s['rpe'] ?? null) ? ' · '.$s['rpe'] : ''))->implode(', ') ?: '—' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <a href="{{ route('coach.workouts.edit', $subject) }}" wire:navigate class="text-sm font-semibold text-brand hover:underline dark:text-lime">{{ __('Open workout') }}</a>
@elseif ($subject instanceof \App\Models\Message)
    <a href="{{ route('coach.clients.messages', $item->client) }}" wire:navigate class="text-sm font-semibold text-brand hover:underline dark:text-lime">{{ __('Open conversation') }}</a>
@endif
