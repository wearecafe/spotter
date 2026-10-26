<?php

use App\Models\Message;
use App\Models\User;
use App\Services\Chat;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * One client's conversation with their coaching team, shared by the coach and client pages.
 * No websockets: it polls every few seconds while visible and skips the render when nothing changed.
 */
new class extends Component {
    use WithFileUploads;

    #[Locked]
    public User $client;

    #[Locked]
    public string $empty = '';

    public int $limit = 50;

    public string $body = '';

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $photos = [];

    /** What the page last showed: newest message id and how many of my messages were still unread. */
    #[Locked]
    public string $signature = '';

    public function mount(User $client, string $empty = ''): void
    {
        $this->client = $client;
        $this->empty = $empty;
        $this->authorizeViewer();

        app(Chat::class)->markRead(Auth::user(), $client);
        $this->signature = $this->currentSignature();
    }

    /**
     * Re-checked on every request: access can change while the page is open (reassignment, removal).
     */
    public function hydrate(): void
    {
        $this->authorizeViewer();
    }

    protected function authorizeViewer(): void
    {
        $viewer = Auth::user();

        abort_unless(
            ($viewer->id === $this->client->id && $this->client->coach_id) || $viewer->managesClient($this->client),
            403,
        );
    }

    protected function viewerIsClient(): bool
    {
        return Auth::id() === $this->client->id;
    }

    protected function currentSignature(): string
    {
        $thread = Message::query()->thread($this->client);
        $mine = (clone $thread)->whereNull('read_at');
        $this->viewerIsClient() ? $mine->where('sender_id', $this->client->id) : $mine->where('sender_id', '!=', $this->client->id);

        return ($thread->max('id') ?? 0).':'.$mine->count();
    }

    /**
     * Called by the poll. Cheap when nothing changed: no render, nothing sent back but the snapshot.
     */
    public function checkForNew(Chat $chat): void
    {
        $signature = $this->currentSignature();

        if ($signature === $this->signature) {
            $this->skipRender();

            return;
        }

        $chat->markRead(Auth::user(), $this->client);
        $this->signature = $this->currentSignature();
        unset($this->messages);
    }

    public function loadEarlier(): void
    {
        $this->limit += 50;
        unset($this->messages);
    }

    public function removePhoto(int $index): void
    {
        unset($this->photos[$index]);
        $this->photos = array_values($this->photos);
    }

    public function send(Chat $chat): void
    {
        $this->body = trim($this->body);

        $this->validate([
            'body' => ['required_without:photos', 'nullable', 'string', 'max:4000'],
            'photos' => ['array', 'max:'.Chat::MAX_PHOTOS],
            'photos.*' => ['image', 'max:12288'],
        ], [
            'body.required_without' => __('Write a message or add a photo.'),
            'photos.max' => __('Send up to :count photos at a time.', ['count' => Chat::MAX_PHOTOS]),
        ]);

        $chat->send(Auth::user(), $this->client, $this->body, $this->photos);

        if (! $this->viewerIsClient()) {
            $chat->resolveMessageItems($this->client);
        }

        $this->reset('body', 'photos');
        $this->signature = $this->currentSignature();
        unset($this->messages);
        $this->dispatch('chat-sent');
    }

    /**
     * The latest messages, oldest first, plus one extra row to tell whether there's more.
     *
     * @return Collection<int, Message>
     */
    #[Computed]
    public function messages(): Collection
    {
        return Message::query()->thread($this->client)
            ->with('sender:id,name')
            ->latest('id')
            ->limit($this->limit + 1)
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * Whether a message sits on the viewer's side of the conversation. For coaches that's the
     * whole coaching team's side, so a teammate's replies line up with their own.
     */
    public function onMySide(Message $message): bool
    {
        return $this->viewerIsClient() ? $message->fromClient() : ! $message->fromClient();
    }

    public function dayLabel(Message $message): string
    {
        $date = $message->created_at->copy()->timezone(config('app.timezone'));

        return match (true) {
            $date->isToday() => __('Today'),
            $date->isYesterday() => __('Yesterday'),
            $date->isCurrentYear() => $date->isoFormat('dddd D MMMM'),
            default => $date->isoFormat('D MMMM YYYY'),
        };
    }
}; ?>

@php
    $all = $this->messages;
    $hasMore = $all->count() > $limit;
    $messages = $hasMore ? $all->slice(1)->values() : $all;
    $lastMine = $messages->filter(fn ($m) => $this->onMySide($m))->last();
    $viewerId = auth()->id();
@endphp

<div class="flex min-h-0 flex-1 flex-col" wire:poll.5s.visible="checkForNew" data-test="chat">
    <div
        x-data="{
            stick: true,
            restoreFrom: null,
            init() {
                this.bottom();
                new MutationObserver(() => {
                    if (this.restoreFrom !== null) {
                        this.$el.scrollTop += this.$el.scrollHeight - this.restoreFrom;
                        this.restoreFrom = null;
                    } else if (this.stick) {
                        this.bottom();
                    }
                }).observe(this.$refs.list, { childList: true, subtree: true });
            },
            bottom() { this.$nextTick(() => this.$el.scrollTop = this.$el.scrollHeight) },
            onScroll() { this.stick = this.$el.scrollHeight - this.$el.scrollTop - this.$el.clientHeight < 80 },
        }"
        x-on:scroll.passive="onScroll"
        x-on:load.capture="if (stick) bottom()"
        x-on:chat-sent.window="stick = true; bottom()"
        class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-1 py-2"
    >
        <div x-ref="list" class="flex flex-col gap-1" role="log" aria-live="polite" aria-relevant="additions">
            @if ($hasMore)
                <div class="flex justify-center pb-3">
                    <flux:button size="sm" variant="subtle" wire:click="loadEarlier" x-on:click="restoreFrom = $root.scrollHeight">{{ __('Load earlier messages') }}</flux:button>
                </div>
            @endif

            @forelse ($messages as $i => $message)
                @php
                    $mine = $this->onMySide($message);
                    $previous = $messages[$i - 1] ?? null;
                    $next = $messages[$i + 1] ?? null;
                    $newDay = ! $previous || ! $previous->created_at->isSameDay($message->created_at);
                    $groupStart = $newDay || $previous->sender_id !== $message->sender_id || $message->created_at->diffInMinutes($previous->created_at, true) > 10;
                    $groupEnd = ! $next || $next->sender_id !== $message->sender_id || ! $next->created_at->isSameDay($message->created_at) || $next->created_at->diffInMinutes($message->created_at, true) > 10;
                    // Name a sender when it isn't obvious: the coach seen by a client, or a teammate seen by a coach.
                    $showName = $groupStart && $message->sender_id !== $viewerId && ($mine || ! $message->fromClient());
                @endphp

                @if ($newDay)
                    <div class="my-3 flex items-center gap-3 text-xs font-medium text-zinc-500 dark:text-zinc-400" wire:key="day-{{ $message->id }}">
                        <div class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></div>
                        {{ $this->dayLabel($message) }}
                        <div class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></div>
                    </div>
                @endif

                <div @class(['flex flex-col', 'items-end' => $mine, 'items-start' => ! $mine, 'mt-2' => $groupStart && ! $newDay]) wire:key="message-{{ $message->id }}">
                    @if ($showName)
                        <div class="mb-1 px-1 text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ $message->sender?->name }}</div>
                    @endif

                    @if ($message->photos)
                        <div @class(['mb-1 grid max-w-[80%] gap-1', 'grid-cols-2' => count($message->photos) > 1])>
                            @foreach ($message->photos as $index => $path)
                                <a href="{{ route('media.message-photo', [$message, $index]) }}" target="_blank" rel="noopener" class="block overflow-hidden rounded-xl bg-zinc-100 dark:bg-zinc-700">
                                    <img src="{{ route('media.message-photo', [$message, $index]) }}" alt="{{ __('Photo from :name', ['name' => $message->sender?->name]) }}" loading="lazy" class="max-h-64 w-full object-cover">
                                </a>
                            @endforeach
                        </div>
                    @endif

                    @if (trim($message->body) !== '')
                        <div @class([
                            'max-w-[80%] rounded-2xl px-4 py-2 text-[15px] leading-relaxed whitespace-pre-line break-words',
                            'bg-brand text-white' => $mine,
                            'bg-zinc-100 text-zinc-900 dark:bg-zinc-700 dark:text-white' => ! $mine,
                            'rounded-br-md' => $mine && $groupEnd,
                            'rounded-bl-md' => ! $mine && $groupEnd,
                        ])>{{ $message->body }}</div>
                    @endif

                    @if ($groupEnd)
                        <div class="mt-1 px-1 text-xs text-zinc-500 dark:text-zinc-400">
                            {{ $message->created_at->copy()->timezone(config('app.timezone'))->isoFormat('LT') }}
                            @if ($lastMine && $message->id === $lastMine->id && $message->read_at)
                                · {{ __('Seen') }}
                            @endif
                        </div>
                    @endif
                </div>
            @empty
                <flux:text class="py-12 text-center">{{ $empty ?: __('No messages yet.') }}</flux:text>
            @endforelse
        </div>
    </div>

    <form
        wire:submit="send"
        x-on:keydown.enter="if ($event.target.tagName === 'TEXTAREA' && ! $event.shiftKey && ! $event.isComposing && ! window.matchMedia('(pointer: coarse)').matches) { $event.preventDefault(); $wire.send() }"
        class="border-t border-zinc-200 pt-3 dark:border-zinc-700"
    >
        @if ($photos)
            <div class="mb-2 flex flex-wrap gap-2">
                @foreach ($photos as $index => $photo)
                    <div class="relative size-16 overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-700" wire:key="upload-{{ $index }}">
                        @if ($photo->isPreviewable())
                            <img src="{{ $photo->temporaryUrl() }}" alt="" class="size-full object-cover">
                        @endif
                        <button type="button" wire:click="removePhoto({{ $index }})" class="absolute end-0.5 top-0.5 rounded-full bg-black/60 p-0.5 text-white" aria-label="{{ __('Remove photo') }}">
                            <flux:icon.x-mark variant="micro" />
                        </button>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="flex items-end gap-2">
            <label class="inline-flex size-10 shrink-0 cursor-pointer items-center justify-center rounded-lg text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-700 dark:hover:text-white" title="{{ __('Add photos') }}">
                <flux:icon.photo />
                <span class="sr-only">{{ __('Add photos') }}</span>
                <input type="file" wire:model="photos" multiple accept="image/*" class="sr-only">
            </label>
            <flux:textarea wire:model="body" rows="1" resize="none" :placeholder="__('Write a message…')" :aria-label="__('Message')" class="max-h-40 flex-1 [field-sizing:content]" />
            <flux:button type="submit" variant="primary" icon="paper-airplane" :aria-label="__('Send')" data-test="send-message" wire:loading.attr="disabled" wire:target="send,photos" />
        </div>
        <div wire:loading wire:target="photos" class="mt-1"><flux:text size="sm">{{ __('Uploading…') }}</flux:text></div>
        <flux:error name="body" />
        <flux:error name="photos" />
        <flux:error name="photos.*" />
    </form>
</div>
