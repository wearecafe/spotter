@props(['row'])

@php
    $client = $row['client'];
    $session = $row['session'];
    $next = $row['next'];
    $available = $session !== null || $next !== null;
@endphp

<label wire:key="train-{{ $client->id }}" @class([
    'flex items-center gap-3 rounded-lg px-2 py-2.5',
    'cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800' => $available,
    'opacity-60' => ! $available,
])>
    <flux:checkbox wire:model.live="selected" :value="$client->id" :disabled="! $available" :aria-label="__('Train :name', ['name' => $client->name])" />
    <flux:avatar size="sm" :name="$client->name" :initials="$client->initials()" />
    <span class="flex min-w-0 flex-1 flex-col">
        <span class="flex items-center gap-2">
            <span class="truncate font-medium text-zinc-900 dark:text-white">{{ $client->name }}</span>
            @if ($client->isInPersonOnly())
                <flux:badge size="sm" color="zinc">{{ __('In person') }}</flux:badge>
            @endif
        </span>
        <span class="truncate text-sm text-zinc-500">
            @if ($session)
                {{ $session->title }}
                @if ($session->isCompleted())
                    · <span class="text-green-700 dark:text-green-400">{{ __('done') }}</span>
                @elseif ($session->wasLoggedByTrainer() || $session->isStarted())
                    · {{ __('in progress') }}
                @endif
            @elseif ($next)
                {{ __('Next: :title, :day (moves to today)', ['title' => $next->title, 'day' => $next->scheduled_on->translatedFormat('D j M')]) }}
            @else
                {{ __('Nothing scheduled') }}
            @endif
        </span>
    </span>
</label>
