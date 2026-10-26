<?php

use App\Models\Team;
use App\Services\TenantStats;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::admin')] #[Title('Tenants')] class extends Component {
    use WithPagination;

    /**
     * Staff only, checked on every request (initial load and every action), not just the route.
     */
    public function boot(): void
    {
        abort_unless(auth()->user()?->is_staff, 404);
    }

    #[Url]
    public string $search = '';

    #[Url]
    public string $plan = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $sort = 'newest';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'plan', 'status', 'sort'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function platform(): array
    {
        return app(TenantStats::class)->platform();
    }

    #[Computed]
    public function teams()
    {
        return app(TenantStats::class)->teamsQuery()
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->orWhereHas('owner', fn ($q) => $q->where('email', 'like', "%{$this->search}%")->orWhere('name', 'like', "%{$this->search}%"))))
            ->when(array_key_exists($this->plan, Team::PLANS), fn ($q) => $q->where('plan', $this->plan))
            ->when($this->status === 'suspended', fn ($q) => $q->whereNotNull('suspended_at'))
            ->when($this->status === 'active', fn ($q) => $q->whereNull('suspended_at'))
            ->when($this->status === 'comped', fn ($q) => $q->where('comp_until', '>=', today()->toDateString()))
            ->when($this->sort === 'clients', fn ($q) => $q->orderByDesc('clients_count'))
            ->when($this->sort === 'active', fn ($q) => $q->orderByDesc('last_active_at'))
            ->when($this->sort === 'newest', fn ($q) => $q->latest())
            ->paginate(25);
    }
}; ?>

<div class="flex flex-col gap-6">
    <flux:heading size="xl" level="1">{{ __('Tenants') }}</flux:heading>

    <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
        @foreach ([
            [__('Tenants'), number_format($this->platform['teams']), __('+:n in 30 days', ['n' => $this->platform['new_teams_30d']])],
            [__('Coaches'), number_format($this->platform['coaches']), null],
            [__('Clients'), number_format($this->platform['clients']), null],
            [__('Active clients'), number_format($this->platform['active_clients_7d']), __('last 7 days')],
            [__('Workouts logged'), number_format($this->platform['workouts_7d']), __('last 7 days')],
            [__('Est. MRR'), '$'.number_format($this->platform['mrr_estimate'], 2), __('at launch prices, not billed')],
        ] as [$label, $value, $sub])
            <div class="rounded-xl border border-zinc-200 bg-white p-4">
                <div class="text-xs font-medium uppercase tracking-wide text-zinc-500">{{ $label }}</div>
                <div class="mt-1 font-display text-2xl font-extrabold tabular-nums">{{ $value }}</div>
                @if ($sub)<div class="text-xs text-zinc-500">{{ $sub }}</div>@endif
            </div>
        @endforeach
    </div>

    <div class="flex flex-wrap gap-3">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Team, owner name or email')" class="max-w-xs" />
        <flux:select wire:model.live="plan" class="max-w-40" :aria-label="__('Plan')">
            <flux:select.option value="">{{ __('All plans') }}</flux:select.option>
            @foreach (\App\Models\Team::PLANS as $key => $label)
                <flux:select.option :value="$key">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="status" class="max-w-40" :aria-label="__('Status')">
            <flux:select.option value="">{{ __('Any status') }}</flux:select.option>
            <flux:select.option value="active">{{ __('Active') }}</flux:select.option>
            <flux:select.option value="comped">{{ __('Comped') }}</flux:select.option>
            <flux:select.option value="suspended">{{ __('Suspended') }}</flux:select.option>
        </flux:select>
        <flux:select wire:model.live="sort" class="max-w-48" :aria-label="__('Sort')">
            <flux:select.option value="newest">{{ __('Newest first') }}</flux:select.option>
            <flux:select.option value="clients">{{ __('Most clients') }}</flux:select.option>
            <flux:select.option value="active">{{ __('Recently active') }}</flux:select.option>
        </flux:select>
    </div>

    <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white">
        <table class="w-full min-w-[56rem] text-sm">
            <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500">
                <tr>
                    <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Tenant') }}</th>
                    <th scope="col" class="px-3 py-3 text-start font-semibold">{{ __('Plan') }}</th>
                    <th scope="col" class="px-3 py-3 text-end font-semibold">{{ __('Coaches') }}</th>
                    <th scope="col" class="px-3 py-3 text-end font-semibold">{{ __('Clients') }}</th>
                    <th scope="col" class="px-3 py-3 text-end font-semibold">{{ __('Active 7d') }}</th>
                    <th scope="col" class="px-3 py-3 text-start font-semibold">{{ __('Last active') }}</th>
                    <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Signed up') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @forelse ($this->teams as $team)
                    <tr wire:key="t-{{ $team->id }}" class="hover:bg-zinc-50">
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.tenants.show', $team) }}" wire:navigate class="font-semibold hover:underline">{{ $team->name }}</a>
                            <div class="text-xs text-zinc-500">{{ $team->owner?->email ?? __('No owner') }}</div>
                        </td>
                        <td class="px-3 py-3">
                            <span class="inline-flex flex-wrap gap-1">
                                <flux:badge size="sm">{{ $team->planLabel() }}</flux:badge>
                                @if ($team->isSuspended())<flux:badge size="sm" color="red">{{ __('Suspended') }}</flux:badge>@endif
                                @if ($team->isComped())<flux:badge size="sm" color="lime">{{ __('Comped') }}</flux:badge>@endif
                            </span>
                        </td>
                        <td class="px-3 py-3 text-end tabular-nums">{{ $team->coaches_count }}</td>
                        <td class="px-3 py-3 text-end tabular-nums">{{ $team->clients_count }}</td>
                        <td class="px-3 py-3 text-end tabular-nums">{{ $team->active_clients_count }}</td>
                        <td class="px-3 py-3 text-zinc-600">{{ $team->last_active_at ? \Illuminate\Support\Carbon::parse($team->last_active_at)->diffForHumans() : '—' }}</td>
                        <td class="px-4 py-3 text-zinc-600">{{ $team->created_at->toFormattedDateString() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-zinc-500">{{ __('No tenants match.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $this->teams->links() }}
</div>
