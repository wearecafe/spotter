<?php

use App\Livewire\Concerns\HandlesInboxItems;
use App\Models\InboxItem;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Inbox')] class extends Component {
    use HandlesInboxItems;

    #[Url]
    public string $type = '';

    #[Url(as: 'item')]
    public ?int $selectedId = null;

    #[Computed]
    public function items()
    {
        return Auth::user()->inboxItems()
            ->actionable()
            ->when($this->type, fn ($q) => $q->where('type', $this->type))
            ->ranked()
            ->with('client')
            ->limit(100)
            ->get();
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function counts(): array
    {
        return Auth::user()->inboxItems()->actionable()
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type')
            ->all();
    }

    #[Computed]
    public function selected(): ?InboxItem
    {
        $item = $this->items->firstWhere('id', $this->selectedId) ?? $this->items->first();

        return $item?->load('subject');
    }

    public function select(int $id): void
    {
        $this->selectedId = $id;
        unset($this->selected);
    }

    public function filter(string $type): void
    {
        $this->type = $this->type === $type ? '' : $type;
        $this->selectedId = null;
        unset($this->items, $this->selected);
    }

    protected function afterInboxChange(InboxItem $item): void
    {
        $index = $this->items->search(fn ($i) => $i->id === $item->id);
        $next = $this->items->get($index + 1) ?? $this->items->get($index - 1);
        $this->selectedId = $next?->id;

        unset($this->items, $this->counts, $this->selected);
    }
}; ?>

<div class="flex flex-col gap-5" @if ($this->items->contains(fn ($i) => $i->drafted_by === null)) wire:poll.4s @endif>
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Inbox') }}</flux:heading>
            <flux:text class="mt-1">
                {{ $this->items->isEmpty() ? __('Nothing needs you right now.') : trans_choice(':count thing needs you, most urgent first.|:count things need you, most urgent first.', $this->items->count()) }}
            </flux:text>
        </div>
        <flux:button icon="device-phone-mobile" :href="route('coach.inbox.focus')" wire:navigate>{{ __('One at a time') }}</flux:button>
    </div>

    <div class="flex flex-wrap gap-2" role="toolbar" aria-label="{{ __('Filter by type') }}">
        @foreach (\App\Models\InboxItem::LABELS as $key => $label)
            @if (($this->counts[$key] ?? 0) > 0 || $type === $key)
                <button type="button" wire:click="filter('{{ $key }}')" aria-pressed="{{ $type === $key ? 'true' : 'false' }}"
                    @class([
                        'inline-flex h-9 items-center gap-2 rounded-full border px-4 text-sm font-semibold transition',
                        'border-ink bg-ink text-white dark:border-lime dark:bg-lime dark:text-ink' => $type === $key,
                        'border-zinc-300 bg-white text-zinc-800 hover:border-zinc-500 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100' => $type !== $key,
                    ])>
                    {{ __($label) }} <span class="tabular-nums opacity-70">{{ $this->counts[$key] ?? 0 }}</span>
                </button>
            @endif
        @endforeach
    </div>

    @if ($this->items->isEmpty())
        <flux:card class="flex flex-col items-center gap-2 py-16 text-center">
            <span class="flex size-14 items-center justify-center rounded-full bg-lime text-2xl font-bold text-ink" aria-hidden="true">✓</span>
            <flux:heading size="lg">{{ __('Inbox clear') }}</flux:heading>
            <flux:text>{{ __('Check-ins, form checks, feedback and messages from clients will land here.') }}</flux:text>
        </flux:card>
    @else
        <div class="grid gap-5 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
            <nav aria-label="{{ __('Inbox items') }}" class="flex flex-col gap-2">
                @foreach ($this->items as $item)
                    @php($active = $this->selected?->id === $item->id)
                    <button type="button" wire:click="select({{ $item->id }})" wire:key="row-{{ $item->id }}" aria-current="{{ $active ? 'true' : 'false' }}"
                        @class([
                            'flex w-full items-start gap-3 rounded-xl border p-3 text-start transition',
                            'border-brand bg-white ring-2 ring-brand/20 dark:border-lime dark:bg-zinc-900 dark:ring-lime/20' => $active,
                            'border-zinc-200 bg-white hover:border-zinc-400 dark:border-zinc-700 dark:bg-zinc-900' => ! $active,
                        ])>
                        <flux:avatar size="sm" :name="$item->client->name" :initials="$item->client->initials()" />
                        <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                            <span class="flex items-center justify-between gap-2">
                                <span class="truncate font-semibold text-zinc-900 dark:text-white">{{ $item->client->name }}</span>
                                <span class="shrink-0 text-xs text-zinc-500">{{ $item->created_at->diffForHumans(short: true) }}</span>
                            </span>
                            <span class="text-xs font-semibold uppercase tracking-wide text-brand dark:text-lime">{{ $item->label() }}</span>
                            <span class="truncate text-sm text-zinc-600 dark:text-zinc-300">{{ $item->summary ?: $item->headline }}</span>
                        </span>
                    </button>
                @endforeach
            </nav>

            @if ($item = $this->selected)
                <flux:card class="flex min-w-0 flex-col gap-5" wire:key="detail-{{ $item->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <flux:avatar :name="$item->client->name" :initials="$item->client->initials()" />
                            <div>
                                <a href="{{ route('coach.clients.show', $item->client) }}" wire:navigate class="font-display text-lg font-bold text-zinc-900 hover:underline dark:text-white">{{ $item->client->name }}</a>
                                <flux:text size="sm">{{ $item->label() }} · {{ $item->headline }}@if ($item->client->goal) · {{ $item->client->goal }}@endif</flux:text>
                            </div>
                        </div>
                        <flux:badge size="sm" :color="$item->client->locale === 'fr' ? 'blue' : 'zinc'">{{ strtoupper($item->client->locale) }}</flux:badge>
                    </div>

                    <section class="flex flex-col gap-2 rounded-xl bg-lime/25 p-4 dark:bg-lime/10" aria-label="{{ __('Summary') }}">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-xs font-bold uppercase tracking-wide text-zinc-700 dark:text-zinc-300">
                                {{ match ($item->drafted_by) { 'ai' => __('AI summary'), 'rules' => __('Summary'), default => __('Drafting…') } }}
                            </span>
                            @if ($item->drafted_by)
                                <flux:button size="xs" variant="ghost" icon="arrow-path" wire:click="redraft({{ $item->id }})">{{ __('Redraft') }}</flux:button>
                            @endif
                        </div>
                        <p class="text-[15px] leading-relaxed text-zinc-900 dark:text-zinc-100">{{ $item->summary ?: __('Reading the latest from :name…', ['name' => str($item->client->name)->before(' ')]) }}</p>
                    </section>

                    <x-inbox-context :item="$item" />

                    @if ($s = $item->suggestion)
                        <section class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" aria-label="{{ __('Suggested change') }}">
                            <div class="flex flex-col gap-1">
                                <span class="text-xs font-bold uppercase tracking-wide text-zinc-500">{{ __('Suggested change to the next workout') }}</span>
                                <span class="font-semibold text-zinc-900 dark:text-white">
                                    {{ $s['exercise'] ?? '' }}: {{ __($s['field']) }} <span class="text-zinc-500 line-through">{{ $s['from'] ?: '—' }}</span> → {{ $s['to'] }}
                                </span>
                                @if ($s['reason'] ?? null)
                                    <flux:text size="sm">{{ $s['reason'] }}</flux:text>
                                @endif
                            </div>
                            @if ($s['applied'] ?? false)
                                <flux:badge color="green" icon="check">{{ __('Applied') }}</flux:badge>
                            @else
                                <flux:button size="sm" wire:click="applySuggestion({{ $item->id }})" data-test="apply-suggestion">{{ __('Apply') }}</flux:button>
                            @endif
                        </section>
                    @endif

                    <section class="flex flex-col gap-3" aria-label="{{ __('Reply') }}">
                        <flux:textarea wire:model="replies.{{ $item->id }}" :label="__('Reply to :name', ['name' => str($item->client->name)->before(' ')])" rows="4"
                            :placeholder="$item->drafted_by ? __('Write a reply…') : __('Drafting a reply…')" />
                        <flux:error name="replies.{{ $item->id }}" />
                        <div class="flex flex-wrap justify-between gap-2">
                            <div class="flex gap-2">
                                <flux:button variant="ghost" icon="clock" wire:click="snooze({{ $item->id }})">{{ __('Snooze') }}</flux:button>
                                <flux:button variant="ghost" icon="check" wire:click="markDone({{ $item->id }})">{{ __('Done, no reply') }}</flux:button>
                            </div>
                            <flux:button variant="primary" icon="paper-airplane" wire:click="approve({{ $item->id }})" data-test="approve-reply">{{ __('Approve and send') }}</flux:button>
                        </div>
                    </section>
                </flux:card>
            @endif
        </div>
    @endif
</div>
