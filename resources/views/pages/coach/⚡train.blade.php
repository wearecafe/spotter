<?php

use App\Models\User;
use App\Models\Workout;
use App\Services\TrainerSession;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Train')] class extends Component {
    public string $search = '';

    /** @var array<int, int|string> client ids ticked for this session */
    public array $selected = [];

    /**
     * Clients with a session today, then everyone else (searchable), each with what they'd train.
     *
     * @return array{today: \Illuminate\Support\Collection<int, array{client: User, session: ?Workout, next: ?Workout}>, others: \Illuminate\Support\Collection<int, array{client: User, session: ?Workout, next: ?Workout}>}
     */
    #[Computed]
    public function rows(): array
    {
        $clients = Auth::user()->visibleClients()
            ->with([
                'assignedWorkouts' => fn ($q) => $q->where(fn ($q) => $q
                    ->whereDate('scheduled_on', today())
                    ->orWhere(fn ($q) => $q->whereNull('completed_at')->whereDate('scheduled_on', '>', today())))
                    ->orderBy('scheduled_on')->orderBy('id'),
            ])
            ->orderBy('name')
            ->get()
            ->map(function (User $client) {
                $today = $client->assignedWorkouts->filter(fn (Workout $w) => $w->scheduled_on->isToday())
                    ->sortBy(fn (Workout $w) => $w->completed_at !== null)->first();

                return [
                    'client' => $client,
                    'session' => $today,
                    'next' => $today ? null : $client->assignedWorkouts->first(fn (Workout $w) => $w->scheduled_on->gt(today())),
                ];
            });

        $search = mb_strtolower(trim($this->search));

        return [
            'today' => $clients->filter(fn (array $row) => $row['session'] !== null)->values(),
            'others' => $clients->filter(fn (array $row) => $row['session'] === null)
                ->filter(fn (array $row) => $search === '' || str_contains(mb_strtolower($row['client']->name), $search))
                ->values(),
        ];
    }

    public function start(TrainerSession $sessions): void
    {
        $ids = collect($this->selected)->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            Flux::toast(variant: 'warning', text: __('Pick at least one client.'));

            return;
        }

        $clients = Auth::user()->visibleClients()->whereIn('id', $ids)->orderBy('name')->get();
        $workouts = [];
        $nothing = [];

        foreach ($clients as $client) {
            $session = $sessions->start($client);
            $session ? $workouts[] = $session->id : $nothing[] = $client->name;
        }

        if ($nothing !== []) {
            Flux::toast(variant: 'warning', text: __('Nothing scheduled for :names. Build them a workout first.', ['names' => implode(', ', $nothing)]));
        }

        if ($workouts !== []) {
            $this->redirectRoute('coach.session', ['w' => implode(',', $workouts)], navigate: true);
        }
    }
}; ?>

<div class="mx-auto flex w-full max-w-3xl flex-col gap-6 pb-28">
    <div>
        <flux:heading size="xl" level="1">{{ __('Train') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Run a session in the gym and log it for your clients. Pick one client, or several training together.') }}</flux:text>
    </div>

    @php($rows = $this->rows)

    <flux:card class="flex flex-col gap-1">
        <flux:heading size="lg" class="mb-2">{{ __('Today') }}</flux:heading>
        @forelse ($rows['today'] as $row)
            <x-train-row :row="$row" />
        @empty
            <flux:text>{{ __('No sessions on the calendar today. Pick a client below to pull their next session forward.') }}</flux:text>
        @endforelse
    </flux:card>

    <flux:card class="flex flex-col gap-1">
        <div class="mb-2 flex flex-wrap items-center justify-between gap-3">
            <flux:heading size="lg">{{ __('Everyone else') }}</flux:heading>
            <flux:input wire:model.live.debounce.250ms="search" icon="magnifying-glass" size="sm" :placeholder="__('Search clients')" class="max-w-56" />
        </div>
        @forelse ($rows['others'] as $row)
            <x-train-row :row="$row" />
        @empty
            <flux:text>{{ $search !== '' ? __('No clients match.') : __('Everyone has a session today.') }}</flux:text>
        @endforelse
    </flux:card>

    <div class="fixed inset-x-0 bottom-0 z-30 border-t border-zinc-200 bg-white/95 px-4 py-3 backdrop-blur lg:start-64 dark:border-zinc-700 dark:bg-zinc-900/95">
        <div class="mx-auto flex max-w-3xl items-center justify-between gap-4">
            <flux:text>{{ trans_choice('{0} No one picked yet|{1} :count client picked|[2,*] :count clients picked', count($selected), ['count' => count($selected)]) }}</flux:text>
            <flux:button variant="primary" icon="play" wire:click="start" :disabled="count($selected) === 0" data-test="start-session">
                {{ count($selected) > 1 ? __('Start group session') : __('Start session') }}
            </flux:button>
        </div>
    </div>
</div>
