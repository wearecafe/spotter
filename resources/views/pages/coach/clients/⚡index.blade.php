<?php

use App\Enums\Role;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Clients')] class extends Component {
    public string $search = '';

    public string $name = '';

    public string $email = '';

    public string $goal = '';

    /** For team admins: which coach the new client is assigned to, and the list filter. */
    public string $coachId = '';

    public string $coachFilter = '';

    #[Computed]
    public function teamCoaches()
    {
        return Auth::user()->isTeamAdmin()
            ? User::query()->whereIn('id', Auth::user()->teamCoachIds())->orderBy('name')->get(['id', 'name'])
            : collect();
    }

    #[Computed]
    public function clients()
    {
        return Auth::user()->visibleClients()
            ->when($this->coachFilter !== '', fn ($q) => $q->where('coach_id', (int) $this->coachFilter))
            ->with('coach:id,name')
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('email', 'like', "%{$this->search}%")))
            ->with(['assignedWorkouts' => fn ($q) => $q->where('scheduled_on', '>=', today())->whereNull('completed_at')->orderBy('scheduled_on')])
            ->orderBy('name')
            ->get();
    }

    public function addClient(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)],
            'goal' => ['nullable', 'string', 'max:255'],
            'coachId' => ['nullable', Rule::in(Auth::user()->isTeamAdmin() ? Auth::user()->teamCoachIds() : [Auth::id()])],
        ]);

        $client = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'goal' => $validated['goal'],
            'role' => Role::Client,
            'coach_id' => $this->coachId !== '' ? (int) $this->coachId : Auth::id(),
            'invitation_token' => Str::random(48),
        ]);

        $this->reset('name', 'email', 'goal', 'coachId');
        Flux::modal('add-client')->close();
        Flux::toast(variant: 'success', text: __(':name added. Share their invite link to get them started.', ['name' => $client->name]));

        $this->redirectRoute('coach.clients.show', $client, navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Clients') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Everyone you coach, at a glance.') }}</flux:text>
        </div>

        <flux:modal.trigger name="add-client">
            <flux:button variant="primary" icon="plus" data-test="add-client-button">{{ __('Add client') }}</flux:button>
        </flux:modal.trigger>
    </div>

    <div class="flex flex-wrap gap-3">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search clients')" class="max-w-sm" />
        @if ($this->teamCoaches->count() > 1)
            <flux:select wire:model.live="coachFilter" class="max-w-56" :aria-label="__('Filter by coach')">
                <flux:select.option value="">{{ __('All coaches') }}</flux:select.option>
                @foreach ($this->teamCoaches as $coach)
                    <flux:select.option :value="$coach->id">{{ $coach->name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif
    </div>

    @if ($this->clients->isEmpty())
        <flux:card class="flex flex-col items-center gap-3 py-12 text-center">
            <flux:icon.users class="size-10 text-zinc-400" />
            <flux:heading>{{ $search ? __('No clients match your search') : __('No clients yet') }}</flux:heading>
            @unless ($search)
                <flux:text>{{ __('Add your first client to start programming their training.') }}</flux:text>
            @endunless
        </flux:card>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Client') }}</flux:table.column>
                @if ($this->teamCoaches->count() > 1)
                    <flux:table.column>{{ __('Coach') }}</flux:table.column>
                @endif
                <flux:table.column>{{ __('Goal') }}</flux:table.column>
                <flux:table.column>{{ __('Next workout') }}</flux:table.column>
                <flux:table.column>{{ __('30-day compliance') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->clients as $client)
                    @php($next = $client->assignedWorkouts->first())
                    @php($compliance = $client->complianceRate())
                    <flux:table.row :key="$client->id">
                        <flux:table.cell>
                            <a href="{{ route('coach.clients.show', $client) }}" wire:navigate class="flex items-center gap-3">
                                <flux:avatar size="sm" :name="$client->name" :initials="$client->initials()" />
                                <div class="flex flex-col">
                                    <span class="font-medium text-zinc-900 hover:underline dark:text-white">{{ $client->name }}</span>
                                    <span class="text-xs text-zinc-500">{{ $client->email }}</span>
                                </div>
                                @unless ($client->hasAcceptedInvitation())
                                    <flux:badge size="sm" color="amber">{{ __('Invited') }}</flux:badge>
                                @endunless
                            </a>
                        </flux:table.cell>
                        @if ($this->teamCoaches->count() > 1)
                            <flux:table.cell>{{ $client->coach?->name }}</flux:table.cell>
                        @endif
                        <flux:table.cell>{{ $client->goal ?? '—' }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($next)
                                {{ $next->title }} <span class="text-zinc-500">· {{ $next->scheduled_on->isToday() ? __('Today') : $next->scheduled_on->format('D, M j') }}</span>
                            @else
                                <span class="text-zinc-500">{{ __('Nothing scheduled') }}</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($compliance === null)
                                <span class="text-zinc-500">—</span>
                            @else
                                <flux:badge size="sm" :color="$compliance >= 80 ? 'green' : ($compliance >= 50 ? 'amber' : 'red')">{{ $compliance }}%</flux:badge>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    <flux:modal name="add-client" class="md:w-96">
        <form wire:submit="addClient" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Add a client') }}</flux:heading>
                <flux:text class="mt-2">{{ __("You'll get an invite link to send them so they can set a password.") }}</flux:text>
            </div>

            <flux:input wire:model="name" :label="__('Name')" required />
            <flux:input wire:model="email" :label="__('Email')" type="email" required />
            <flux:input wire:model="goal" :label="__('Goal')" :placeholder="__('e.g. Lose 10 lb, first pull-up')" />
            @if ($this->teamCoaches->count() > 1)
                <flux:select wire:model="coachId" :label="__('Coach')">
                    <flux:select.option value="">{{ __('Me') }}</flux:select.option>
                    @foreach ($this->teamCoaches->where('id', '!=', auth()->id()) as $coach)
                        <flux:select.option :value="$coach->id">{{ $coach->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-client-button">{{ __('Add client') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
