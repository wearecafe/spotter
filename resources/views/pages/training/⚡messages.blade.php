<?php

use App\Models\Message;
use App\Models\User;
use App\Services\InboxFeed;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Messages')] class extends Component {
    public string $body = '';

    public function mount(): void
    {
        abort_unless($this->client->coach_id, 404);

        Message::query()->thread($this->client)
            ->where('sender_id', '!=', $this->client->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    #[Computed]
    public function client(): User
    {
        return Auth::user();
    }

    #[Computed]
    public function messages()
    {
        return Message::query()->thread($this->client)->oldest()->limit(200)->get();
    }

    public function send(InboxFeed $feed): void
    {
        $this->validate(['body' => ['required', 'string', 'max:4000']]);

        $message = Message::create([
            'coach_id' => $this->client->coach_id,
            'client_id' => $this->client->id,
            'sender_id' => $this->client->id,
            'body' => trim($this->body),
        ]);

        $feed->messageReceived($message);

        $this->body = '';
        unset($this->messages);
    }
}; ?>

<div class="mx-auto flex w-full max-w-2xl flex-col gap-4" wire:poll.15s>
    <flux:heading size="xl" level="1">{{ __('Messages with :coach', ['coach' => $this->client->coach->name]) }}</flux:heading>

    <flux:card>
        <x-message-thread :messages="$this->messages" :me="$this->client" :empty="__('No messages yet. Say hi to your coach.')" />
    </flux:card>

    <form wire:submit="send" class="flex items-end gap-2">
        <flux:textarea wire:model="body" rows="2" :placeholder="__('Write a message…')" :aria-label="__('Message')" class="flex-1" resize="none" />
        <flux:button type="submit" variant="primary" icon="paper-airplane" data-test="send-message">{{ __('Send') }}</flux:button>
    </form>
</div>
