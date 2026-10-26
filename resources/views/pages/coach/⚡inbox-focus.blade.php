<?php

use App\Livewire\Concerns\HandlesInboxItems;
use App\Models\InboxItem;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Inbox')] class extends Component {
    use HandlesInboxItems;

    /** Items cleared this session, for the progress dots. */
    public int $cleared = 0;

    #[Computed]
    public function items()
    {
        return Auth::user()->inboxItems()->actionable()->ranked()->with('client')->limit(50)->get();
    }

    #[Computed]
    public function current(): ?InboxItem
    {
        return $this->items->first()?->load('subject');
    }

    public function skip(): void
    {
        // Skipping moves the card to the back of the queue for this session by snoozing it briefly.
        if ($item = $this->current) {
            $item->update(['status' => 'snoozed', 'snoozed_until' => now()->addHour()]);
            unset($this->items, $this->current);
        }
    }

    protected function afterInboxChange(InboxItem $item): void
    {
        $this->cleared++;
        unset($this->items, $this->current);
    }
}; ?>

<div class="mx-auto flex w-full max-w-md flex-col gap-4" @if ($this->current && ! $this->current->drafted_by) wire:poll.4s @endif>
    <div class="flex items-center justify-between">
        <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('coach.inbox')" wire:navigate>{{ __('All items') }}</flux:button>
        @php($total = $this->cleared + $this->items->count())
        @if ($total > 0)
            <div class="flex items-center gap-1.5" aria-label="{{ __(':done of :total done', ['done' => $this->cleared, 'total' => $total]) }}">
                @foreach (range(1, min($total, 12)) as $n)
                    <span @class(['h-2 rounded-full transition-all', 'w-5 bg-brand dark:bg-lime' => $n === $this->cleared + 1, 'w-2 bg-brand/40 dark:bg-lime/40' => $n <= $this->cleared, 'w-2 bg-zinc-300 dark:bg-zinc-600' => $n > $this->cleared + 1])></span>
                @endforeach
            </div>
        @endif
    </div>

    @if ($item = $this->current)
        <flux:card class="flex flex-col gap-4" wire:key="focus-{{ $item->id }}">
            <div class="flex items-center gap-3">
                <flux:avatar :name="$item->client->name" :initials="$item->client->initials()" />
                <div class="min-w-0">
                    <div class="truncate font-display text-lg font-bold text-zinc-900 dark:text-white">{{ $item->client->name }}</div>
                    <span class="text-xs font-semibold uppercase tracking-wide text-brand dark:text-lime">{{ $item->label() }}</span>
                </div>
            </div>

            <p class="rounded-xl bg-lime/25 p-4 text-[15px] leading-relaxed text-zinc-900 dark:bg-lime/10 dark:text-zinc-100">
                {{ $item->summary ?: __('Drafting…') }}
            </p>

            <x-inbox-context :item="$item" />

            @if (($s = $item->suggestion) && ! ($s['applied'] ?? false))
                <div class="flex items-center justify-between gap-3 rounded-xl border border-zinc-200 p-3 text-sm dark:border-zinc-700">
                    <span><span class="font-semibold">{{ $s['exercise'] ?? '' }}</span>: {{ __($s['field']) }} {{ $s['from'] ?: '—' }} → {{ $s['to'] }}</span>
                    <flux:button size="sm" wire:click="applySuggestion({{ $item->id }})">{{ __('Apply') }}</flux:button>
                </div>
            @endif

            <flux:textarea wire:model="replies.{{ $item->id }}" rows="4" :aria-label="__('Reply')" :placeholder="__('Write a reply…')" />
            <flux:error name="replies.{{ $item->id }}" />

            <flux:button variant="primary" class="w-full" icon="paper-airplane" wire:click="approve({{ $item->id }})">{{ __('Approve and send') }}</flux:button>
            <div class="grid grid-cols-3 gap-2">
                <flux:button size="sm" variant="ghost" wire:click="snooze({{ $item->id }})">{{ __('Snooze') }}</flux:button>
                <flux:button size="sm" variant="ghost" wire:click="markDone({{ $item->id }})">{{ __('Done') }}</flux:button>
                <flux:button size="sm" variant="ghost" wire:click="skip">{{ __('Skip') }}</flux:button>
            </div>
        </flux:card>
    @else
        <flux:card class="flex flex-col items-center gap-3 py-16 text-center">
            <span class="flex size-16 items-center justify-center rounded-full bg-lime text-3xl font-bold text-ink" aria-hidden="true">✓</span>
            <flux:heading size="xl">{{ __('Inbox clear') }}</flux:heading>
            <flux:text>{{ $this->cleared ? trans_choice('You handled :count item. Nice.|You handled :count items. Nice.', $this->cleared) : __('Nothing needs you right now.') }}</flux:text>
        </flux:card>
    @endif
</div>
