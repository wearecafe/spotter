@props(['messages', 'me', 'empty'])

<div {{ $attributes->class('flex flex-col gap-3') }} role="log" aria-live="polite">
    @forelse ($messages as $message)
        @php($mine = $message->sender_id === $me->id)
        <div @class(['flex', 'justify-end' => $mine]) wire:key="message-{{ $message->id }}">
            <div @class([
                'max-w-[80%] rounded-2xl px-4 py-2.5',
                'bg-brand text-white rounded-br-sm' => $mine,
                'bg-zinc-100 text-zinc-900 rounded-bl-sm dark:bg-zinc-700 dark:text-white' => ! $mine,
            ])>
                <div class="whitespace-pre-line text-[15px] leading-relaxed">{{ $message->body }}</div>
                <div @class(['mt-1 text-xs', 'text-white/70' => $mine, 'text-zinc-500 dark:text-zinc-400' => ! $mine])>
                    {{ $message->created_at->diffForHumans() }}
                </div>
            </div>
        </div>
    @empty
        <flux:text class="py-8 text-center">{{ $empty }}</flux:text>
    @endforelse
</div>
