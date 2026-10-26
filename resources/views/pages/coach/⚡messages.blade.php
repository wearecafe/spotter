<?php

use App\Models\Message;
use App\Models\User;
use App\Services\Chat;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Messages')] class extends Component {
    #[Url(as: 'client')]
    public ?int $clientId = null;

    public string $search = '';

    public function mount(): void
    {
        if ($this->clientId && ! $this->selected) {
            abort(403);
        }

        // Read before the list renders, so the open conversation doesn't show as unread.
        if ($this->selected) {
            app(Chat::class)->markRead(Auth::user(), $this->selected);
        }
    }

    #[Computed]
    public function selected(): ?User
    {
        if (! $this->clientId) {
            return null;
        }

        $client = User::find($this->clientId);

        return Auth::user()->managesClient($client) ? $client : null;
    }

    /**
     * Every client this coach can message, most recent conversation first.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function conversations(): Collection
    {
        $coach = Auth::user();
        $term = trim($this->search);

        $clients = $coach->visibleClients()
            ->with('coach:id,name')
            ->addSelect([
                'last_message_id' => Message::query()->select('id')->whereColumn('client_id', 'users.id')->latest('id')->limit(1),
                'unread_count' => Message::query()->selectRaw('count(*)')->whereColumn('client_id', 'users.id')->whereColumn('sender_id', 'users.id')->whereNull('read_at'),
            ])
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q->whereLike('name', "%{$term}%")->orWhereLike('email', "%{$term}%")))
            ->get()
            // Sorted here rather than in SQL: databases disagree on where nulls go.
            ->sortBy([fn ($a, $b) => ($b->last_message_id ?? 0) <=> ($a->last_message_id ?? 0), fn ($a, $b) => strcasecmp($a->name, $b->name)])
            ->take(200)
            ->values();

        $last = Message::query()->whereIn('id', $clients->pluck('last_message_id')->filter())->get()->keyBy('id');
        $clients->each(fn (User $client) => $client->setRelation('lastMessage', $last->get($client->last_message_id)));

        return $clients;
    }
}; ?>

@php($selected = $this->selected)

<div class="-m-6 flex h-[calc(100dvh-3.5rem)] lg:-m-8 lg:h-dvh">
    {{-- Conversation list: always on desktop, only without a selection on phones. --}}
    <aside @class([
        'w-full flex-col border-e border-zinc-200 dark:border-zinc-700 lg:flex lg:w-80 lg:shrink-0',
        'hidden' => $selected,
        'flex' => ! $selected,
    ]) wire:poll.15s.visible>
        <div class="flex flex-col gap-3 border-b border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="xl" level="1">{{ __('Messages') }}</flux:heading>
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search clients')" :aria-label="__('Search clients')" size="sm" clearable />
        </div>

        <nav class="min-h-0 flex-1 overflow-y-auto" aria-label="{{ __('Conversations') }}">
            @forelse ($this->conversations as $client)
                @php($active = $selected?->id === $client->id)
                <a
                    href="{{ route('coach.messages', ['client' => $client->id]) }}"
                    wire:navigate
                    wire:key="conversation-{{ $client->id }}"
                    @class([
                        'flex items-start gap-3 border-b border-zinc-100 px-4 py-3 dark:border-zinc-800',
                        'bg-zinc-100 dark:bg-zinc-700/60' => $active,
                        'hover:bg-zinc-50 dark:hover:bg-zinc-800' => ! $active,
                    ])
                    @if ($active) aria-current="page" @endif
                >
                    <flux:avatar size="sm" :name="$client->name" :initials="$client->initials()" />
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline justify-between gap-2">
                            <span @class(['truncate text-sm text-zinc-900 dark:text-white', 'font-semibold' => $client->unread_count, 'font-medium' => ! $client->unread_count])>{{ $client->name }}</span>
                            @if ($client->lastMessage)
                                <span class="shrink-0 text-xs text-zinc-500 dark:text-zinc-400">{{ $client->lastMessage->created_at->isToday() ? $client->lastMessage->created_at->copy()->timezone(config('app.timezone'))->isoFormat('LT') : $client->lastMessage->created_at->copy()->timezone(config('app.timezone'))->isoFormat('D MMM') }}</span>
                            @endif
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <span @class(['truncate text-sm', 'text-zinc-900 dark:text-white' => $client->unread_count, 'text-zinc-500 dark:text-zinc-400' => ! $client->unread_count])>
                                @if ($client->lastMessage)
                                    @unless ($client->lastMessage->fromClient()) {{ __('You:') }} @endunless
                                    {{ $client->lastMessage->preview(60) }}
                                @else
                                    {{ __('No messages yet') }}
                                @endif
                            </span>
                            @if ($client->unread_count)
                                <flux:badge size="sm" color="red" class="shrink-0">{{ $client->unread_count }}</flux:badge>
                            @endif
                        </div>
                        @if ($client->coach_id !== auth()->id() && $client->coach)
                            <div class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Coach: :name', ['name' => $client->coach->name]) }}</div>
                        @endif
                    </div>
                </a>
            @empty
                <flux:text class="p-6 text-center">{{ trim($search) !== '' ? __('No clients match “:search”.', ['search' => $search]) : __('Invite a client to start a conversation.') }}</flux:text>
            @endforelse
        </nav>
    </aside>

    {{-- Thread --}}
    <section @class(['min-w-0 flex-1 flex-col p-4 lg:flex lg:px-8', 'flex' => $selected, 'hidden' => ! $selected])>
        @if ($selected)
            <div class="flex items-center gap-3 border-b border-zinc-200 pb-3 dark:border-zinc-700">
                <flux:button :href="route('coach.messages')" wire:navigate icon="arrow-left" variant="ghost" size="sm" class="lg:hidden" :aria-label="__('All conversations')" />
                <flux:avatar :name="$selected->name" :initials="$selected->initials()" />
                <div class="min-w-0 flex-1">
                    <flux:heading size="lg" level="2" class="truncate">{{ $selected->name }}</flux:heading>
                    @if (! $selected->hasAcceptedInvitation())
                        <flux:text size="sm">{{ __('Hasn’t joined yet. They’ll see your messages when they do.') }}</flux:text>
                    @elseif ($selected->coach_id !== auth()->id())
                        <flux:text size="sm">{{ __('Coached by :name', ['name' => $selected->coach?->name]) }}</flux:text>
                    @endif
                </div>
                <flux:button size="sm" :href="route('coach.clients.show', $selected)" wire:navigate>{{ __('Profile') }}</flux:button>
            </div>

            <livewire:pages::chat.thread :client="$selected" :empty="__('No messages yet.')" :key="'thread-'.$selected->id" />
        @else
            <div class="flex flex-1 flex-col items-center justify-center gap-2 text-center">
                <flux:icon.chat-bubble-left-right class="size-10 text-zinc-400" />
                <flux:heading>{{ __('Pick a conversation') }}</flux:heading>
                <flux:text>{{ __('Messages from clients also show up in your inbox with a drafted reply.') }}</flux:text>
            </div>
        @endif
    </section>
</div>
