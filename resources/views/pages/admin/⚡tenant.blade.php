<?php

use App\Models\StaffAuditLog;
use App\Models\Team;
use App\Services\TenantStats;
use Flux\Flux;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::admin')] #[Title('Tenant')] class extends Component {

    /**
     * Staff only, checked on every request (initial load and every action), not just the route.
     */
    public function boot(): void
    {
        abort_unless(auth()->user()?->is_staff, 404);
    }
    public Team $team;

    public string $plan = 'starter';

    public int $compMonths = 1;

    public string $suspendReason = '';

    public string $notes = '';

    public function mount(Team $team): void
    {
        $this->team = $team->load('owner');
        $this->plan = $team->plan;
        $this->notes = (string) $team->staff_notes;
    }

    #[Computed]
    public function stats(): array
    {
        return app(TenantStats::class)->forTeam($this->team);
    }

    #[Computed]
    public function coaches()
    {
        return $this->team->coaches()->withCount('clients')->orderByRaw("case team_role when 'owner' then 0 when 'admin' then 1 else 2 end")->get();
    }

    #[Computed]
    public function log()
    {
        return $this->team->auditLogs()->with('staff:id,name,email')->limit(50)->get();
    }

    public function savePlan(): void
    {
        $this->validate(['plan' => ['required', Rule::in(array_keys(Team::PLANS))]]);

        if ($this->plan === $this->team->plan) {
            return;
        }

        StaffAuditLog::record('plan_changed', $this->team, ['from' => $this->team->plan, 'to' => $this->plan]);
        $this->team->update(['plan' => $this->plan]);
        $this->done(__('Plan changed to :plan.', ['plan' => $this->team->planLabel()]));
    }

    public function comp(): void
    {
        $this->validate(['compMonths' => ['required', 'integer', 'between:1,24']]);

        $from = $this->team->isComped() ? $this->team->comp_until : today();
        $until = $from->addMonths($this->compMonths);

        StaffAuditLog::record('comp_granted', $this->team, ['months' => $this->compMonths, 'until' => $until->toDateString()]);
        $this->team->update(['comp_until' => $until]);
        $this->done(__('Free until :date.', ['date' => $until->toFormattedDateString()]));
    }

    public function endComp(): void
    {
        StaffAuditLog::record('comp_ended', $this->team, ['was_until' => $this->team->comp_until?->toDateString()]);
        $this->team->update(['comp_until' => null]);
        $this->done(__('Comp removed.'));
    }

    public function suspend(): void
    {
        $this->validate(['suspendReason' => ['required', 'string', 'max:255']], attributes: ['suspendReason' => __('reason')]);

        StaffAuditLog::record('suspended', $this->team, ['reason' => $this->suspendReason]);
        $this->team->update(['suspended_at' => now(), 'suspended_reason' => $this->suspendReason]);
        $this->suspendReason = '';
        Flux::modal('suspend')->close();
        $this->done(__('Suspended. Coaches and clients are signed out on their next request.'));
    }

    public function reactivate(): void
    {
        StaffAuditLog::record('reactivated', $this->team, ['was_reason' => $this->team->suspended_reason]);
        $this->team->update(['suspended_at' => null, 'suspended_reason' => null]);
        $this->done(__('Reactivated.'));
    }

    public function saveNotes(): void
    {
        $this->validate(['notes' => ['nullable', 'string', 'max:5000']]);

        StaffAuditLog::record('notes_updated', $this->team);
        $this->team->update(['staff_notes' => $this->notes ?: null]);
        $this->done(__('Notes saved.'));
    }

    public function sendOwnerReset(): void
    {
        abort_unless($this->team->owner, 404);

        Password::sendResetLink(['email' => $this->team->owner->email]);
        StaffAuditLog::record('password_reset_sent', $this->team, ['to' => $this->team->owner->email]);
        $this->done(__('Password reset email sent to :email.', ['email' => $this->team->owner->email]));
    }

    protected function done(string $message): void
    {
        $this->team->refresh();
        unset($this->log);
        Flux::toast(variant: 'success', text: $message);
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <a href="{{ route('admin.tenants') }}" wire:navigate class="text-sm text-zinc-500 hover:underline">← {{ __('Tenants') }}</a>
            <div class="mt-1 flex flex-wrap items-center gap-2">
                <flux:heading size="xl" level="1">{{ $team->name }}</flux:heading>
                <flux:badge>{{ $team->planLabel() }}</flux:badge>
                @if ($team->isSuspended())<flux:badge color="red">{{ __('Suspended') }}</flux:badge>@endif
                @if ($team->isComped())<flux:badge color="lime">{{ __('Free until :date', ['date' => $team->comp_until->toFormattedDateString()]) }}</flux:badge>@endif
            </div>
            <flux:text class="mt-1">{{ __('Owner: :owner · signed up :date · tenant #:id', ['owner' => $team->owner ? $team->owner->name.' ('.$team->owner->email.')' : __('none'), 'date' => $team->created_at->toFormattedDateString(), 'id' => $team->id]) }}</flux:text>
        </div>
        <div class="flex gap-2">
            @if ($team->owner)
                <flux:button size="sm" icon="key" wire:click="sendOwnerReset" wire:confirm="{{ __('Email the owner a password reset link?') }}">{{ __('Send owner a password reset') }}</flux:button>
            @endif
            @if ($team->isSuspended())
                <flux:button size="sm" variant="primary" wire:click="reactivate" wire:confirm="{{ __('Reactivate this tenant?') }}" data-test="reactivate">{{ __('Reactivate') }}</flux:button>
            @else
                <flux:modal.trigger name="suspend"><flux:button size="sm" variant="danger">{{ __('Suspend') }}</flux:button></flux:modal.trigger>
            @endif
        </div>
    </div>

    @if ($team->isSuspended())
        <flux:callout icon="no-symbol" color="red">
            <flux:callout.heading>{{ __('Suspended :when', ['when' => $team->suspended_at->diffForHumans()]) }}</flux:callout.heading>
            <flux:callout.text>{{ $team->suspended_reason }}</flux:callout.text>
        </flux:callout>
    @endif

    <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
        @foreach ([
            [__('Clients'), $this->stats['clients'], trans_choice(':count invite pending|:count invites pending', $this->stats['clients_pending_invite'])],
            [__('Active clients'), $this->stats['active_clients_7d'], __('last 7 days')],
            [__('Workouts completed'), $this->stats['workouts_completed'], __('last 30 days')],
            [__('Check-ins'), $this->stats['check_ins'], __('last 30 days')],
            [__('Nutrition days'), $this->stats['nutrition_days'], __('last 30 days')],
            [__('Messages'), $this->stats['messages'], __('last 30 days')],
            [__('Inbox drafts (AI)'), $this->stats['inbox_ai'], __('last 30 days')],
            [__('Inbox drafts (rules)'), $this->stats['inbox_rules'], __('last 30 days')],
            [__('Imports'), $this->stats['imports'], __('all time')],
            [__('Est. monthly'), '$'.number_format(app(\App\Services\TenantStats::class)->monthlyEstimate($team, $this->stats['clients']), 2), __('at launch prices')],
        ] as [$label, $value, $sub])
            <div class="rounded-xl border border-zinc-200 bg-white p-4">
                <div class="text-xs font-medium uppercase tracking-wide text-zinc-500">{{ $label }}</div>
                <div class="mt-1 font-display text-2xl font-extrabold tabular-nums">{{ $value }}</div>
                <div class="text-xs text-zinc-500">{{ $sub }}</div>
            </div>
        @endforeach
    </div>
    <flux:text size="sm">{{ __('Client names and health data are never shown here.') }}</flux:text>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="flex min-w-0 flex-col gap-6">
            <section class="overflow-x-auto rounded-xl border border-zinc-200 bg-white">
                <h2 class="px-4 pt-4 font-display text-lg font-bold">{{ __('Coaches') }}</h2>
                <table class="mt-2 w-full min-w-[36rem] text-sm">
                    <thead class="text-xs uppercase tracking-wide text-zinc-500">
                        <tr><th class="px-4 py-2 text-start font-semibold">{{ __('Name') }}</th><th class="px-3 py-2 text-start font-semibold">{{ __('Role') }}</th><th class="px-3 py-2 text-end font-semibold">{{ __('Clients') }}</th><th class="px-4 py-2 text-start font-semibold">{{ __('Last seen') }}</th></tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100">
                        @foreach ($this->coaches as $coach)
                            <tr>
                                <td class="px-4 py-2"><div class="font-medium">{{ $coach->name }}</div><div class="text-xs text-zinc-500">{{ $coach->email }}</div></td>
                                <td class="px-3 py-2">{{ \App\Models\Team::ROLES[$coach->team_role] ?? '—' }}</td>
                                <td class="px-3 py-2 text-end tabular-nums">{{ $coach->clients_count }}</td>
                                <td class="px-4 py-2 text-zinc-600">{{ $coach->last_seen_at?->diffForHumans() ?? __('never') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>

            <section class="rounded-xl border border-zinc-200 bg-white p-4">
                <h2 class="font-display text-lg font-bold">{{ __('Staff activity') }}</h2>
                <ul class="mt-3 flex flex-col gap-2 text-sm">
                    @forelse ($this->log as $entry)
                        <li class="flex flex-wrap justify-between gap-2 border-t border-zinc-100 pt-2 first:border-0 first:pt-0">
                            <span><strong>{{ str_replace('_', ' ', $entry->action) }}</strong>@if ($entry->details) <span class="text-zinc-500">· {{ collect($entry->details)->map(fn ($v, $k) => $k.': '.$v)->implode(', ') }}</span>@endif</span>
                            <span class="text-zinc-500">{{ $entry->staff?->email ?? __('system') }} · {{ $entry->created_at->diffForHumans() }}</span>
                        </li>
                    @empty
                        <li class="text-zinc-500">{{ __('No staff actions yet.') }}</li>
                    @endforelse
                </ul>
            </section>
        </div>

        <div class="flex flex-col gap-4">
            <section class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-white p-4">
                <h2 class="font-display text-lg font-bold">{{ __('Plan') }}</h2>
                <div class="flex gap-2">
                    <flux:select wire:model="plan" class="flex-1" :aria-label="__('Plan')">
                        @foreach (\App\Models\Team::PLANS as $key => $label)
                            <flux:select.option :value="$key">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:button wire:click="savePlan" data-test="save-plan">{{ __('Save') }}</flux:button>
                </div>
                <flux:text size="sm">{{ __('Billing isn’t live yet; this sets the plan the tenant will be billed on.') }}</flux:text>
            </section>

            <section class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-white p-4">
                <h2 class="font-display text-lg font-bold">{{ __('Free months') }}</h2>
                <flux:text size="sm">{{ $team->isComped() ? __('Free until :date.', ['date' => $team->comp_until->toFormattedDateString()]) : __('Not comped.') }}</flux:text>
                <div class="flex gap-2">
                    <flux:input type="number" min="1" max="24" wire:model="compMonths" class="w-24" :aria-label="__('Months')" />
                    <flux:button wire:click="comp" data-test="grant-comp">{{ __('Add months') }}</flux:button>
                    @if ($team->comp_until)
                        <flux:button variant="ghost" wire:click="endComp">{{ __('End') }}</flux:button>
                    @endif
                </div>
            </section>

            <section class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-white p-4">
                <h2 class="font-display text-lg font-bold">{{ __('Staff notes') }}</h2>
                <flux:textarea wire:model="notes" rows="5" :aria-label="__('Staff notes')" :placeholder="__('Only Spotter staff see these.')" />
                <flux:button size="sm" class="self-start" wire:click="saveNotes">{{ __('Save notes') }}</flux:button>
            </section>
        </div>
    </div>

    <flux:modal name="suspend" class="md:w-[28rem]">
        <form wire:submit="suspend" class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Suspend :team?', ['team' => $team->name]) }}</flux:heading>
            <flux:text>{{ __('Every coach and client in this tenant is signed out and can’t sign back in until it’s reactivated. Their data is kept.') }}</flux:text>
            <flux:input wire:model="suspendReason" :label="__('Reason (staff only)')" :placeholder="__('e.g. chargeback, abuse report')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="danger" data-test="confirm-suspend">{{ __('Suspend') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
