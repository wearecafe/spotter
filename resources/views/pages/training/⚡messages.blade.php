<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Messages')] class extends Component {
    public function mount(): void
    {
        abort_unless($this->client->coach_id, 404);
    }

    #[Computed]
    public function client(): User
    {
        return Auth::user();
    }
}; ?>

<div class="mx-auto flex h-[calc(100dvh-7.5rem)] w-full max-w-2xl flex-col gap-3 lg:h-[calc(100dvh-4rem)]">
    <div class="flex items-center gap-3">
        <flux:avatar :name="$this->client->coach->name" :initials="$this->client->coach->initials()" />
        <div>
            <flux:heading size="lg" level="1">{{ $this->client->coach->name }}</flux:heading>
            <flux:text size="sm">{{ $this->client->coach->brandName() }}</flux:text>
        </div>
    </div>

    <livewire:pages::chat.thread :client="$this->client" :empty="__('No messages yet. Say hi to your coach.')" />
</div>
