<?php

use App\Models\Exercise;
use App\Models\Import;
use App\Services\ProgramImporter;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Import review')] class extends Component {
    use WithFileUploads;

    public Import $import;

    public string $tab = 'exercises';

    /** Raw name currently being re-matched. */
    public ?string $picking = null;

    public string $exerciseSearch = '';

    /** @var array<int, mixed> */
    public array $moreFiles = [];

    /**
     * Re-checked on every action: access can change while the page is open (reassignment, removal).
     */
    public function hydrate(): void
    {
        Gate::authorize('manage-import', $this->import);
    }

    public function mount(Import $import): void
    {
        Gate::authorize('manage-import', $import);
        $this->import = $import;
    }

    #[Computed]
    public function exercises()
    {
        return Exercise::query()->availableTo(Auth::user())->pluck('name', 'id');
    }

    #[Computed]
    public function stats(): array
    {
        $programs = collect($this->import->programs ?? []);
        $matches = collect($this->import->matches ?? []);

        return [
            'clients' => Auth::user()->clients()->count(),
            'created' => $this->import->stats['clients_created'] ?? 0,
            'programs_total' => $programs->count(),
            'programs_ok' => $programs->reject(fn ($p) => $p['unreadable'] ?? false)->count(),
            'names_total' => $matches->count(),
            'names_resolved' => $matches->where('resolved', true)->count(),
        ];
    }

    #[Computed]
    public function spotCheck(): ?array
    {
        $program = collect($this->import->programs ?? [])->first(fn ($p) => ! ($p['unreadable'] ?? false));

        return $program ? ['client' => $program['client_name'], 'day' => $program['days'][0]] : null;
    }

    protected function updateMatch(string $raw, array $changes): void
    {
        abort_if($this->import->status !== 'review', 403);

        $matches = $this->import->matches ?? [];
        abort_unless(isset($matches[$raw]), 404);

        $matches[$raw] = array_merge($matches[$raw], $changes);
        $this->import->update(['matches' => $matches]);
        unset($this->stats);
    }

    public function accept(string $raw): void
    {
        $match = $this->import->matches[$raw] ?? null;

        if ($match && $match['exercise_id']) {
            $this->updateMatch($raw, ['resolved' => true]);
        }
    }

    public function acceptAll(): void
    {
        abort_if($this->import->status !== 'review', 403);

        $matches = collect($this->import->matches ?? [])
            ->map(fn ($m) => $m['exercise_id'] ? array_merge($m, ['resolved' => true]) : $m)
            ->all();

        $this->import->update(['matches' => $matches]);
        unset($this->stats);
    }

    public function startPicking(string $raw): void
    {
        $this->picking = $raw;
        $this->exerciseSearch = '';
        Flux::modal('import-picker')->show();
    }

    #[Computed]
    public function pickerResults()
    {
        return Exercise::query()
            ->availableTo(Auth::user())
            ->when($this->exerciseSearch, fn ($q) => $q->where('name', 'like', "%{$this->exerciseSearch}%"))
            ->orderBy('name')
            ->limit(12)
            ->get();
    }

    public function pick(int $exerciseId): void
    {
        $exercise = Exercise::query()->availableTo(Auth::user())->findOrFail($exerciseId);

        $this->updateMatch($this->picking, ['exercise_id' => $exercise->id, 'suggestion' => $exercise->name, 'resolved' => true]);
        $this->picking = null;
        Flux::modal('import-picker')->close();
    }

    public function createCustom(string $raw): void
    {
        abort_if($this->import->status !== 'review', 403);
        abort_unless(isset($this->import->matches[$raw]), 404);

        $exercise = Auth::user()->exercises()->create(['name' => Str::of($raw)->squish()->ucfirst()->limit(120, '')->toString()]);

        $this->updateMatch($raw, ['exercise_id' => $exercise->id, 'suggestion' => $exercise->name, 'resolved' => true]);
        unset($this->exercises);
    }

    public function addFiles(): void
    {
        abort_if($this->import->status === 'finished', 403);
        $this->validate(['moreFiles' => ['required', 'array', 'max:200'], 'moreFiles.*' => ['file', 'mimes:pdf,txt,csv', 'max:10240']]);

        \App\Jobs\ProcessImportFiles::start($this->import, $this->moreFiles);
        $this->moreFiles = [];
        $this->import->refresh();
        unset($this->stats, $this->spotCheck);
    }

    public function refreshImport(): void
    {
        $this->import->refresh();
        unset($this->stats, $this->spotCheck);
    }

    public function finish(ProgramImporter $importer): void
    {
        if ($this->import->status !== 'review') {
            return;
        }

        if ($this->import->unresolvedCount() > 0) {
            Flux::toast(variant: 'warning', text: __('Confirm the remaining exercise names first.'));

            return;
        }

        $created = $importer->finish($this->import);

        Flux::toast(variant: 'success', text: trans_choice(':count program rebuilt.|:count programs rebuilt.', $created));
        $this->redirectRoute('coach.setup', ['step' => 3], navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6" @if ($import->status === 'processing') wire:poll.3s="refreshImport" @endif>
    @if ($import->status === 'processing')
        <flux:callout icon="arrow-path">
            <flux:callout.heading>{{ __('Reading your program files…') }}</flux:callout.heading>
            <flux:callout.text>{{ __('PDFs take a few seconds each. This page updates on its own.') }}</flux:callout.text>
        </flux:callout>
    @endif

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:text>{{ __('Importing from :source', ['source' => __($import->sourceLabel())]) }}</flux:text>
            <flux:heading size="xl" level="1" class="mt-1">
                @if ($import->status === 'finished')
                    {{ __('Import finished') }}
                @elseif ($import->unresolvedCount() > 0)
                    {{ trans_choice(':count exercise needs a quick look|:count exercises need a quick look', $import->unresolvedCount()) }}
                @else
                    {{ __('All set. Ready to finish') }}
                @endif
            </flux:heading>
        </div>
        @if ($import->status === 'finished')
            <flux:button :href="route('coach.setup', ['step' => 3])" wire:navigate>{{ __('Continue setup') }}</flux:button>
        @else
            <flux:button variant="primary" wire:click="finish" :disabled="$import->unresolvedCount() > 0 || $import->status !== 'review'" data-test="finish-import">{{ __('Finish import') }}</flux:button>
        @endif
    </div>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <flux:card class="flex flex-col gap-1">
            <flux:text size="sm">{{ __('Clients') }}</flux:text>
            <span class="font-display text-2xl font-extrabold tabular-nums">{{ $this->stats['clients'] }}</span>
            <flux:text size="sm">{{ trans_choice(':count new from this import|:count new from this import', $this->stats['created']) }}</flux:text>
        </flux:card>
        <flux:card class="flex flex-col gap-1">
            <flux:text size="sm">{{ __('Programs read') }}</flux:text>
            <span class="font-display text-2xl font-extrabold tabular-nums">{{ __(':ok of :total', ['ok' => $this->stats['programs_ok'], 'total' => $this->stats['programs_total']]) }}</span>
            @if ($this->stats['programs_total'] > $this->stats['programs_ok'])
                <flux:text size="sm" class="text-amber-700! dark:text-amber-400!">{{ trans_choice(':count file needs attention|:count files need attention', $this->stats['programs_total'] - $this->stats['programs_ok']) }}</flux:text>
            @endif
        </flux:card>
        <flux:card class="flex flex-col gap-1">
            <flux:text size="sm">{{ __('Exercise names matched') }}</flux:text>
            <span class="font-display text-2xl font-extrabold tabular-nums">{{ __(':ok of :total', ['ok' => $this->stats['names_resolved'], 'total' => $this->stats['names_total']]) }}</span>
        </flux:card>
        <flux:card class="flex flex-col gap-1">
            <flux:text size="sm">{{ __('Workout history') }}</flux:text>
            <span class="font-display text-2xl font-extrabold">{{ __('Not included') }}</span>
            <flux:text size="sm">{{ __('Exports carry programs, not past logs') }}</flux:text>
        </flux:card>
    </div>

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <flux:card class="flex min-w-0 flex-col gap-0 p-0!">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200 px-5 py-3 dark:border-zinc-700">
                <div role="tablist" class="flex gap-1 rounded-xl bg-zinc-100 p-1 dark:bg-zinc-800">
                    @foreach (['exercises' => trans_choice('Exercises to check (:count)|Exercises to check (:count)', $import->unresolvedCount()), 'programs' => __('Programs (:count)', ['count' => $this->stats['programs_total']])] as $key => $label)
                        <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')"
                            @class(['h-9 rounded-lg px-3 text-sm font-semibold', 'bg-white shadow-sm dark:bg-zinc-700' => $tab === $key, 'text-zinc-600 dark:text-zinc-400' => $tab !== $key])>{{ $label }}</button>
                    @endforeach
                </div>
                @if ($tab === 'exercises' && $import->status !== 'finished')
                    <flux:button size="sm" wire:click="acceptAll">{{ __('Accept all suggestions') }}</flux:button>
                @endif
            </div>

            @if ($tab === 'exercises')
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[44rem] text-sm">
                        <thead><tr class="text-xs uppercase tracking-wide text-zinc-500">
                            <th scope="col" class="px-5 py-3 text-start font-medium">{{ __('In your files') }}</th>
                            <th scope="col" class="px-3 py-3 text-start font-medium">{{ __('Spotter exercise') }}</th>
                            <th scope="col" class="px-3 py-3 text-start font-medium">{{ __('Confidence') }}</th>
                            <th scope="col" class="px-3 py-3 text-start font-medium">{{ __('Used by') }}</th>
                            <th scope="col" class="px-5 py-3 text-end font-medium">{{ __('Action') }}</th>
                        </tr></thead>
                        <tbody>
                            @forelse ($import->matches ?? [] as $raw => $match)
                                <tr wire:key="match-{{ md5($raw) }}" @class(['border-t border-zinc-100 dark:border-zinc-700', 'bg-zinc-50/60 dark:bg-zinc-800/40' => $match['resolved']])>
                                    <td class="px-5 py-3 font-mono text-[13px] text-zinc-700 dark:text-zinc-300">{{ $raw }}</td>
                                    <td class="px-3 py-3 font-semibold text-zinc-900 dark:text-white">{{ $match['suggestion'] ?? __('No close match') }}</td>
                                    <td class="px-3 py-3">
                                        <flux:badge size="sm" :color="['high' => 'green', 'medium' => 'amber', 'none' => 'red'][$match['confidence']] ?? 'zinc'">{{ __(ucfirst($match['confidence'])) }}</flux:badge>
                                    </td>
                                    <td class="px-3 py-3 text-zinc-600 dark:text-zinc-400">{{ trans_choice(':count client|:count clients', $match['used']) }}</td>
                                    <td class="px-5 py-2 text-end">
                                        @if ($import->status === 'finished')
                                            <span class="text-zinc-500">—</span>
                                        @elseif ($match['resolved'])
                                            <span class="inline-flex items-center gap-2">
                                                <span class="font-semibold text-brand dark:text-lime">✓ {{ __('Matched') }}</span>
                                                <flux:button size="xs" variant="ghost" wire:click="startPicking(@js($raw))">{{ __('Change') }}</flux:button>
                                            </span>
                                        @else
                                            <span class="inline-flex gap-1.5">
                                                <flux:button size="sm" wire:click="startPicking(@js($raw))">{{ __('Pick') }}</flux:button>
                                                @if ($match['exercise_id'])
                                                    <flux:button size="sm" variant="primary" wire:click="accept(@js($raw))">{{ __('Accept') }}</flux:button>
                                                @else
                                                    <flux:button size="sm" variant="primary" wire:click="createCustom(@js($raw))">{{ __('Create custom') }}</flux:button>
                                                @endif
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-5 py-6"><flux:text>{{ __('No programs uploaded yet. Add program files below.') }}</flux:text></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @else
                <ul class="divide-y divide-zinc-100 dark:divide-zinc-700">
                    @forelse ($import->programs ?? [] as $key => $program)
                        <li class="flex items-center justify-between gap-3 px-5 py-3" wire:key="program-{{ md5($key) }}">
                            <span class="flex flex-col">
                                <span class="font-semibold text-zinc-900 dark:text-white">{{ $program['client_name'] }}</span>
                                <flux:text size="sm">
                                    @if ($program['unreadable'] ?? false)
                                        {{ match ($program['reason'] ?? '') {
                                            'no_client' => __('File name doesn’t match a client. Rename it to the client’s name and upload again.'),
                                            'no_exercises' => __('No exercises found in this file.'),
                                            default => __('This file couldn’t be read.'),
                                        } }}
                                    @else
                                        {{ trans_choice(':count day|:count days', count($program['days'])) }} · {{ collect($program['days'])->sum(fn ($d) => count($d['rows'])) }} {{ __('exercises') }}
                                    @endif
                                </flux:text>
                            </span>
                            @if ($program['unreadable'] ?? false)
                                <flux:badge size="sm" color="amber">{{ __('Needs attention') }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="green">{{ __('Ready') }}</flux:badge>
                            @endif
                        </li>
                    @empty
                        <li class="px-5 py-6"><flux:text>{{ __('No program files yet.') }}</flux:text></li>
                    @endforelse
                </ul>
            @endif
        </flux:card>

        <aside class="flex flex-col gap-4">
            @if ($check = $this->spotCheck)
                <flux:card class="flex flex-col gap-3">
                    <flux:heading>{{ __('Spot check: :client, :day', ['client' => $check['client'], 'day' => $check['day']['title']]) }}</flux:heading>
                    <div class="overflow-hidden rounded-lg border border-zinc-200 text-sm dark:border-zinc-700">
                        @foreach ($check['day']['rows'] as $row)
                            @php($m = $import->matches[$row['raw']] ?? null)
                            <div @class(['flex justify-between gap-3 px-3 py-2', 'border-t border-zinc-100 dark:border-zinc-700' => ! $loop->first, 'bg-amber-50 dark:bg-amber-900/20' => ! ($m['resolved'] ?? false)])>
                                <span class="flex flex-col">
                                    <span class="font-semibold">{{ ($m['resolved'] ?? false) ? $m['suggestion'] : $row['raw'].' ('.__('unmatched').')' }}</span>
                                    @if (($m['resolved'] ?? false) && Str::lower($row['raw']) !== Str::lower($m['suggestion']))
                                        <span class="font-mono text-xs text-zinc-500">{{ $row['raw'] }}</span>
                                    @endif
                                </span>
                                <span class="tabular-nums text-zinc-600 dark:text-zinc-300">{{ $row['sets'] }} × {{ $row['reps'] }}{{ $row['load'] ? ' · '.$row['load'] : '' }}{{ $row['rpe'] ? ' · RPE '.$row['rpe'] : '' }}</span>
                            </div>
                        @endforeach
                    </div>
                </flux:card>
            @endif

            @if ($import->status !== 'finished')
                <flux:card class="flex flex-col gap-3">
                    <flux:heading>{{ __('Add more program files') }}</flux:heading>
                    <form wire:submit="addFiles" class="flex flex-col gap-3">
                        <flux:input type="file" wire:model="moreFiles" multiple accept=".pdf,.txt,.csv" :aria-label="__('Program files')" />
                        <flux:error name="moreFiles.*" />
                        <flux:button type="submit" size="sm" class="self-start">{{ __('Upload') }}</flux:button>
                    </form>
                </flux:card>
            @endif

            @if (auth()->user()->contract_months_left > 0)
                <flux:card class="flex flex-col gap-1">
                    <flux:heading>{{ __('Contract credit applied') }}</flux:heading>
                    <flux:text size="sm">{{ trans_choice(':count month left on your old plan, so your first month here is free.|:count months left on your old plan, so your first :count months here are free.', auth()->user()->contract_months_left) }}</flux:text>
                </flux:card>
            @endif
        </aside>
    </div>

    <flux:modal name="import-picker" class="w-full md:w-[32rem]">
        <div class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Match “:raw”', ['raw' => $picking]) }}</flux:heading>
            <flux:input wire:model.live.debounce.250ms="exerciseSearch" icon="magnifying-glass" :placeholder="__('Search exercises')" autofocus />
            <div class="flex max-h-96 flex-col divide-y divide-zinc-100 overflow-y-auto dark:divide-zinc-700">
                @foreach ($this->pickerResults as $exercise)
                    <button type="button" wire:click="pick({{ $exercise->id }})" wire:key="ip-{{ $exercise->id }}" class="flex items-center justify-between gap-3 px-1 py-2 text-start hover:bg-zinc-50 dark:hover:bg-zinc-700/50">
                        <span class="font-medium text-zinc-900 dark:text-white">{{ $exercise->name }}</span>
                        <span class="text-sm text-zinc-500">{{ $exercise->muscle_group }}</span>
                    </button>
                @endforeach
            </div>
            @if ($picking)
                <flux:button variant="ghost" wire:click="createCustom(@js($picking))" x-on:click="$flux.modal('import-picker').close()">{{ __('None of these. Create “:raw” as a custom exercise', ['raw' => $picking]) }}</flux:button>
            @endif
        </div>
    </flux:modal>
</div>
