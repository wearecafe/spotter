<?php

use App\Enums\Role;
use App\Mail\TeamInvitationMail;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\TeamManager;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Team')] class extends Component {
    public string $teamName = '';

    public string $inviteEmail = '';

    public string $inviteRole = 'coach';

    public ?int $removing = null;

    public string $handTo = '';

    public function mount(): void
    {
        Gate::authorize('manage-team');
        $this->teamName = $this->team->name;
    }

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->team;
    }

    #[Computed]
    public function members()
    {
        return $this->team->coaches()
            ->withCount(['clients as client_count' => fn ($q) => $q->where('role', Role::Client)])
            ->orderByRaw("case team_role when 'owner' then 0 when 'admin' then 1 else 2 end")
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function invitations()
    {
        return $this->team->invitations()->pending()->latest()->get();
    }

    public function rename(): void
    {
        $this->validate(['teamName' => ['required', 'string', 'max:120']], attributes: ['teamName' => __('team name')]);
        $this->team->update(['name' => $this->teamName]);
        Flux::toast(variant: 'success', text: __('Team name saved.'));
    }

    public function invite(): void
    {
        $this->inviteEmail = Str::lower(trim($this->inviteEmail));

        $this->validate([
            'inviteEmail' => ['required', 'email', 'max:255', function ($attribute, $value, $fail) {
                if (User::where('email', $value)->where('team_id', $this->team->id)->exists()) {
                    $fail(__('They’re already on your team.'));
                }
                if (User::where('email', $value)->where('role', Role::Client)->exists()) {
                    $fail(__('That email belongs to a client account.'));
                }
            }],
            'inviteRole' => ['required', Rule::in(['coach', 'admin'])],
        ], attributes: ['inviteEmail' => __('email')]);

        // Re-inviting the same email refreshes the existing invitation instead of piling up new ones.
        $invitation = TeamInvitation::updateOrCreate(
            ['team_id' => $this->team->id, 'email' => $this->inviteEmail, 'accepted_at' => null],
            ['invited_by' => Auth::id(), 'role' => $this->inviteRole, 'token' => Str::random(48), 'expires_at' => now()->addDays(14)],
        );

        Mail::to($invitation->email)->send(new TeamInvitationMail($invitation));

        $this->reset('inviteEmail', 'inviteRole');
        unset($this->invitations);
        Flux::toast(variant: 'success', text: __('Invitation sent.'));
    }

    public function revoke(int $id): void
    {
        $this->team->invitations()->whereKey($id)->whereNull('accepted_at')->delete();
        unset($this->invitations);
    }

    public function changeRole(int $userId, string $role): void
    {
        abort_unless(in_array($role, ['admin', 'coach'], true), 422);

        $member = $this->member($userId);
        abort_if($member->isTeamOwner() || $member->id === Auth::id(), 403);

        $member->forceFill(['team_role' => $role])->save();
        unset($this->members);
    }

    public function startRemove(int $userId): void
    {
        $member = $this->member($userId);
        abort_if($member->isTeamOwner() || $member->id === Auth::id(), 403);

        $this->removing = $member->id;
        $this->handTo = (string) Auth::id();
        Flux::modal('remove-coach')->show();
    }

    public function remove(TeamManager $manager): void
    {
        $member = $this->member((int) $this->removing);
        abort_if($member->isTeamOwner() || $member->id === Auth::id(), 403);
        $handTo = $this->member((int) $this->handTo);
        abort_if($handTo->id === $member->id, 422);

        $manager->removeCoach($member, $handTo);

        $this->removing = null;
        unset($this->members);
        Flux::modal('remove-coach')->close();
        Flux::toast(text: __(':name was removed. Their clients now belong to :coach.', ['name' => $member->name, 'coach' => $handTo->name]));
    }

    protected function member(int $id): User
    {
        return $this->team->coaches()->whereKey($id)->firstOrFail();
    }
}; ?>

<div class="flex max-w-4xl flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Team') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Coaches on your team share the exercise library and templates. Owners and admins can see and reassign every client.') }}</flux:text>
    </div>

    <flux:card>
        <form wire:submit="rename" class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <flux:input wire:model="teamName" :label="__('Team name')" class="sm:flex-1" />
            <flux:button type="submit">{{ __('Save') }}</flux:button>
        </form>
    </flux:card>

    <flux:card class="flex flex-col gap-4">
        <flux:heading size="lg">{{ __('Coaches') }}</flux:heading>
        <div class="divide-y divide-zinc-100 dark:divide-zinc-700">
            @foreach ($this->members as $member)
                <div class="flex flex-wrap items-center justify-between gap-3 py-3" wire:key="member-{{ $member->id }}">
                    <div class="flex items-center gap-3">
                        <flux:avatar size="sm" :name="$member->name" :initials="$member->initials()" />
                        <div>
                            <div class="font-medium text-zinc-900 dark:text-white">{{ $member->name }} @if ($member->id === auth()->id()) <span class="text-zinc-500">({{ __('you') }})</span> @endif</div>
                            <flux:text size="sm">{{ $member->email }} · {{ trans_choice(':count client|:count clients', $member->client_count) }}</flux:text>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        @if ($member->isTeamOwner() || $member->id === auth()->id())
                            <flux:badge>{{ __(\App\Models\Team::ROLES[$member->team_role] ?? $member->team_role) }}</flux:badge>
                        @else
                            <flux:select size="sm" class="w-32" wire:change="changeRole({{ $member->id }}, $event.target.value)" :aria-label="__('Role for :name', ['name' => $member->name])">
                                <flux:select.option value="coach" :selected="$member->team_role === 'coach'">{{ __('Coach') }}</flux:select.option>
                                <flux:select.option value="admin" :selected="$member->team_role === 'admin'">{{ __('Admin') }}</flux:select.option>
                            </flux:select>
                            <flux:button size="sm" variant="ghost" icon="user-minus" wire:click="startRemove({{ $member->id }})" :aria-label="__('Remove :name', ['name' => $member->name])" />
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </flux:card>

    <flux:card class="flex flex-col gap-4">
        <flux:heading size="lg">{{ __('Invite a coach') }}</flux:heading>
        <form wire:submit="invite" class="grid gap-3 sm:grid-cols-[1fr_10rem_auto] sm:items-end">
            <flux:input wire:model="inviteEmail" type="email" :label="__('Email')" :placeholder="__('coach@yourgym.com')" />
            <flux:select wire:model="inviteRole" :label="__('Role')">
                <flux:select.option value="coach">{{ __('Coach') }}</flux:select.option>
                <flux:select.option value="admin">{{ __('Admin') }}</flux:select.option>
            </flux:select>
            <flux:button type="submit" variant="primary" data-test="send-team-invite">{{ __('Send invite') }}</flux:button>
        </form>
        <flux:text size="sm">{{ __('Coaches see their own clients. Admins see every client and can manage the team.') }}</flux:text>

        @if ($this->invitations->isNotEmpty())
            <div class="flex flex-col gap-2 border-t border-zinc-100 pt-4 dark:border-zinc-700">
                <flux:heading>{{ __('Pending invitations') }}</flux:heading>
                @foreach ($this->invitations as $invitation)
                    <div class="flex flex-wrap items-center justify-between gap-3" wire:key="inv-{{ $invitation->id }}">
                        <div>
                            <span class="font-medium">{{ $invitation->email }}</span>
                            <flux:text size="sm">{{ __(\App\Models\Team::ROLES[$invitation->role]) }} · {{ __('expires :when', ['when' => $invitation->expires_at->diffForHumans()]) }}</flux:text>
                        </div>
                        <div class="flex items-center gap-2">
                            <flux:input size="sm" readonly copyable :value="route('team-invitation.accept', $invitation->token)" class="w-64" :aria-label="__('Invitation link')" />
                            <flux:button size="sm" variant="ghost" wire:click="revoke({{ $invitation->id }})">{{ __('Revoke') }}</flux:button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </flux:card>

    <flux:modal name="remove-coach" class="md:w-[28rem]">
        @if ($removing && ($leaving = $this->members->firstWhere('id', $removing)))
            <form wire:submit="remove" class="flex flex-col gap-4">
                <flux:heading size="lg">{{ __('Remove :name?', ['name' => $leaving->name]) }}</flux:heading>
                <flux:text>{{ trans_choice('Their :count client will move to the coach you choose. They keep their login and get a workspace of their own.|Their :count clients will move to the coach you choose. They keep their login and get a workspace of their own.', $leaving->client_count) }}</flux:text>
                <flux:select wire:model="handTo" :label="__('Hand their clients to')">
                    @foreach ($this->members->where('id', '!=', $removing) as $member)
                        <flux:select.option :value="$member->id">{{ $member->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="danger">{{ __('Remove coach') }}</flux:button>
                </div>
            </form>
        @endif
    </flux:modal>
</div>
