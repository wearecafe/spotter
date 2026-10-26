<?php

use App\Models\InboxItem;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Messages')] class extends Component {
    public User $client;

    public string $body = '';

    public function mount(User $client): void
    {
        Gate::authorize('manage-client', $client);

        $this->client = $client;

        Message::query()->thread($client)
            ->where('sender_id', $client->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    #[Computed]
    public function messages()
    {
        return Message::query()->thread($this->client)->latest()->latest('id')->limit(200)->get()->reverse()->values();
    }

    public function send(): void
    {
        $this->validate(['body' => ['required', 'string', 'max:4000']]);

        Message::create([
            'coach_id' => Auth::id(),
            'client_id' => $this->client->id,
            'sender_id' => Auth::id(),
            'body' => trim($this->body),
        ]);

        // Replying here answers any open message item in the inbox.
        InboxItem::query()
            ->where('coach_id', Auth::id())
            ->where('client_id', $this->client->id)
            ->where('type', 'message')
            ->where('status', '!=', 'done')
            ->update(['status' => 'done', 'resolved_at' => now()]);

        $this->body = '';
        unset($this->messages);
    }
}; ?>

<div class="mx-auto flex w-full max-w-2xl flex-col gap-4" wire:poll.15s>
    <div>
        <flux:breadcrumbs>
            <flux:breadcrumbs.item :href="route('coach.clients.index')" wire:navigate>{{ __('Clients') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item :href="route('coach.clients.show', $client)" wire:navigate>{{ $client->name }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ __('Messages') }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>
        <flux:heading size="xl" level="1" class="mt-2">{{ $client->name }}</flux:heading>
    </div>

    <flux:card>
        <x-message-thread :messages="$this->messages" :me="auth()->user()" :empty="__('No messages yet.')" />
    </flux:card>

    <form wire:submit="send" class="flex items-end gap-2">
        <flux:textarea wire:model="body" rows="2" :placeholder="__('Write to :name…', ['name' => str($client->name)->before(' ')])" :aria-label="__('Message')" class="flex-1" resize="none" />
        <flux:button type="submit" variant="primary" icon="paper-airplane">{{ __('Send') }}</flux:button>
    </form>
</div>
