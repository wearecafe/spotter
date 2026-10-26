<?php

use App\Models\FormCheck;
use App\Models\Workout;
use App\Services\InboxFeed;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Workout')] class extends Component {
    use WithFileUploads;

    public const RPE_OPTIONS = ['6', '6.5', '7', '7.5', '8', '8.5', '9', '9.5', '10'];

    public Workout $workout;

    /** @var array<int, array<int, array{reps: string|null, load: string|null, rpe: string|null, done: bool}>> keyed by workout_exercise id */
    public array $logs = [];

    public string $feedback = '';

    /** @var array<int, mixed> form-check uploads keyed by workout_exercise id */
    public array $videos = [];

    /** @var array<int, string> */
    public array $videoNotes = [];

    public function mount(Workout $workout): void
    {
        Gate::authorize('log', $workout);

        $this->workout = $workout->load('items.exercise', 'items.formChecks', 'coach');
        $this->feedback = (string) $workout->client_feedback;

        foreach ($this->workout->items as $item) {
            $this->logs[$item->id] = $item->setsForLogging();
        }
    }

    public function saveProgress(bool $notify = true): void
    {
        $this->validate([
            'logs.*.*.reps' => ['nullable', 'string', 'max:30'],
            'logs.*.*.load' => ['nullable', 'string', 'max:30'],
            'logs.*.*.rpe' => ['nullable', 'in:'.implode(',', self::RPE_OPTIONS)],
            'logs.*.*.done' => ['boolean'],
            'feedback' => ['nullable', 'string', 'max:2000'],
        ]);

        foreach ($this->workout->items as $item) {
            $sets = collect($this->logs[$item->id] ?? [])
                ->map(fn (array $set) => [
                    'reps' => filled($set['reps'] ?? null) ? (string) $set['reps'] : null,
                    'load' => filled($set['load'] ?? null) ? (string) $set['load'] : null,
                    'rpe' => filled($set['rpe'] ?? null) ? (string) $set['rpe'] : null,
                    'done' => (bool) ($set['done'] ?? false),
                ])
                ->values()
                ->all();

            $item->update(['logged_sets' => $sets]);
        }

        $this->workout->update(['client_feedback' => $this->feedback ?: null]);

        if ($notify) {
            Flux::toast(variant: 'success', text: __('Progress saved.'));
        }
    }

    public function complete(InboxFeed $feed): void
    {
        $this->saveProgress(notify: false);

        $firstTime = ! $this->workout->isCompleted();
        $this->workout->update(['completed_at' => now()]);

        if ($firstTime) {
            $feed->workoutCompleted($this->workout->fresh());
        }

        Flux::toast(variant: 'success', heading: __('Workout complete!'), text: __('Nice work. Your coach can see your results.'));
    }

    public function reopen(): void
    {
        $this->workout->update(['completed_at' => null]);
    }

    public function sendFormCheck(int $itemId, InboxFeed $feed): void
    {
        $item = $this->workout->items->firstWhere('id', $itemId) ?? abort(404);

        $this->validate([
            "videos.$itemId" => ['required', 'file', 'mimetypes:video/mp4,video/quicktime,video/webm,video/3gpp,video/x-m4v', 'max:102400'],
            "videoNotes.$itemId" => ['nullable', 'string', 'max:255'],
        ], attributes: ["videos.$itemId" => __('video')]);

        $path = $this->videos[$itemId]->store('form-checks/'.Auth::id(), config('filesystems.media_disk'));

        $formCheck = FormCheck::create([
            'client_id' => Auth::id(),
            'workout_exercise_id' => $item->id,
            'video_path' => $path,
            'note' => ($this->videoNotes[$itemId] ?? '') ?: null,
        ]);

        $feed->formCheckUploaded($formCheck);

        unset($this->videos[$itemId], $this->videoNotes[$itemId]);
        $this->workout->load('items.formChecks');

        Flux::modal("form-check-$itemId")->close();
        Flux::toast(variant: 'success', text: __('Sent to your coach.'));
    }
}; ?>

<div class="mx-auto flex w-full max-w-3xl flex-col gap-6 pb-24"
     x-data="{
        rest: 0, total: 0, label: '', timer: null,
        start(seconds, label) {
            if (! seconds) return;
            clearInterval(this.timer);
            this.rest = seconds; this.total = seconds; this.label = label;
            this.timer = setInterval(() => { if (--this.rest <= 0) { this.stop(); if (navigator.vibrate) navigator.vibrate(300); } }, 1000);
        },
        stop() { clearInterval(this.timer); this.rest = 0; },
        get clock() { return Math.floor(this.rest / 60) + ':' + String(this.rest % 60).padStart(2, '0'); },
     }"
     x-on:start-rest.window="start($event.detail.seconds, $event.detail.label)">
    <div>
        <flux:breadcrumbs>
            <flux:breadcrumbs.item :href="route('training.index')" wire:navigate>{{ __('My workouts') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ $workout->scheduled_on?->translatedFormat('D j M') }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>
        <flux:heading size="xl" level="1" class="mt-2">{{ $workout->title }}</flux:heading>
        @if ($workout->week)
            <flux:text class="mt-1">{{ __('Week :week', ['week' => $workout->week]) }}</flux:text>
        @endif
    </div>

    @if ($workout->instructions)
        <flux:callout icon="chat-bubble-left-ellipsis">
            <flux:callout.heading>{{ __('Note from :coach', ['coach' => str($workout->coach->name)->before(' ')]) }}</flux:callout.heading>
            <flux:callout.text class="whitespace-pre-line">{{ $workout->instructions }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($workout->isCompleted())
        <flux:callout icon="check-circle" color="green">
            <flux:callout.heading>{{ __('Completed :when', ['when' => $workout->completed_at->diffForHumans()]) }}</flux:callout.heading>
            <x-slot name="actions">
                <flux:button size="sm" wire:click="reopen">{{ __('Reopen') }}</flux:button>
            </x-slot>
        </flux:callout>
    @endif

    @foreach ($workout->items as $index => $item)
        <flux:card class="flex flex-col gap-4" wire:key="log-{{ $item->id }}">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="font-display text-lg font-bold text-zinc-900 dark:text-white">{{ $index + 1 }}. {{ $item->exercise->name }}</div>
                    <flux:text>{{ $item->prescription() }}</flux:text>
                    @if ($item->notes)
                        <flux:text size="sm" class="mt-1 italic">{{ $item->notes }}</flux:text>
                    @endif
                </div>
                <div class="flex shrink-0 gap-1">
                    @if ($item->exercise->video_url)
                        <flux:button size="sm" variant="ghost" icon="play-circle" :href="$item->exercise->video_url" target="_blank">{{ __('Demo') }}</flux:button>
                    @endif
                    <flux:modal.trigger :name="'form-check-'.$item->id">
                        <flux:button size="sm" variant="ghost" icon="video-camera">
                            {{ $item->formChecks->isNotEmpty() ? __('Sent ✓') : __('Form check') }}
                        </flux:button>
                    </flux:modal.trigger>
                </div>
            </div>

            <div class="flex flex-col gap-2">
                <div class="grid grid-cols-[1.5rem_minmax(0,1fr)_minmax(0,1.3fr)_4.25rem_2.25rem] gap-2 text-xs font-medium uppercase text-zinc-500">
                    <span>{{ __('Set') }}</span><span>{{ __('Reps') }}</span><span>{{ __('Load') }}</span><span>RPE</span><span class="text-center">{{ __('Done') }}</span>
                </div>
                @foreach ($logs[$item->id] as $setIndex => $set)
                    <div class="grid grid-cols-[1.5rem_minmax(0,1fr)_minmax(0,1.3fr)_4.25rem_2.25rem] items-center gap-2" wire:key="set-{{ $item->id }}-{{ $setIndex }}">
                        <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">{{ $setIndex + 1 }}</span>
                        <flux:input size="sm" wire:model="logs.{{ $item->id }}.{{ $setIndex }}.reps" :placeholder="$item->reps" inputmode="numeric" :aria-label="__('Reps, set :n', ['n' => $setIndex + 1])" />
                        <flux:input size="sm" wire:model="logs.{{ $item->id }}.{{ $setIndex }}.load" :placeholder="$item->load" :aria-label="__('Load, set :n', ['n' => $setIndex + 1])" />
                        <flux:select size="sm" wire:model="logs.{{ $item->id }}.{{ $setIndex }}.rpe" :aria-label="__('RPE, set :n', ['n' => $setIndex + 1])">
                            <flux:select.option value="">—</flux:select.option>
                            @foreach ($this::RPE_OPTIONS as $rpe)
                                <flux:select.option :value="$rpe">{{ $rpe }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <div class="flex justify-center">
                            <flux:checkbox wire:model="logs.{{ $item->id }}.{{ $setIndex }}.done"
                                :aria-label="__('Set :n done', ['n' => $setIndex + 1])"
                                x-on:change="if ($event.target.checked) $dispatch('start-rest', { seconds: {{ (int) $item->rest_seconds }}, label: @js($item->exercise->name) })" />
                        </div>
                    </div>
                @endforeach
            </div>
        </flux:card>

        <flux:modal :name="'form-check-'.$item->id" class="w-full md:w-[28rem]">
            <form wire:submit="sendFormCheck({{ $item->id }})" class="flex flex-col gap-4">
                <div>
                    <flux:heading size="lg">{{ __('Form check: :exercise', ['exercise' => $item->exercise->name]) }}</flux:heading>
                    <flux:text class="mt-1">{{ __('Film a set from the side, about 3 metres away. Your coach will reply with notes.') }}</flux:text>
                </div>
                <flux:input type="file" wire:model="videos.{{ $item->id }}" accept="video/*" capture="environment" :label="__('Video')" />
                <div wire:loading wire:target="videos.{{ $item->id }}"><flux:text size="sm">{{ __('Uploading…') }}</flux:text></div>
                <flux:input wire:model="videoNotes.{{ $item->id }}" :label="__('Anything to look at? (optional)')" :placeholder="__('Knee caves on the last rep?')" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="videos.{{ $item->id }}">{{ __('Send to coach') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endforeach

    @if ($workout->items->isEmpty())
        <flux:card><flux:text>{{ __('Your coach is still building this workout.') }}</flux:text></flux:card>
    @endif

    <flux:card class="flex flex-col gap-4">
        <flux:textarea wire:model="feedback" :label="__('How did it go?')" :placeholder="__('Anything your coach should know: energy, pain, what felt easy or hard.')" rows="3" />
        <div class="flex flex-wrap justify-end gap-2">
            <flux:button wire:click="saveProgress">{{ __('Save progress') }}</flux:button>
            @unless ($workout->isCompleted())
                <flux:button variant="primary" icon="check" wire:click="complete" data-test="complete-workout-button">{{ __('Complete workout') }}</flux:button>
            @endunless
        </div>
    </flux:card>

    {{-- Rest timer: starts when a set is ticked off, using the coach's prescribed rest. --}}
    <div x-cloak x-show="rest > 0" x-transition role="timer" aria-live="polite"
         class="fixed inset-x-0 bottom-0 z-40 border-t border-zinc-800 bg-ink px-4 py-3 text-white lg:start-64">
        <div class="mx-auto flex max-w-3xl items-center justify-between gap-4">
            <div class="flex flex-col">
                <span class="text-xs uppercase tracking-wide text-zinc-400">{{ __('Rest') }} · <span x-text="label"></span></span>
                <span class="font-display text-3xl font-extrabold tabular-nums text-lime" x-text="clock"></span>
            </div>
            <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-zinc-700">
                <div class="h-full bg-lime transition-all duration-1000" :style="`width: ${total ? (rest / total) * 100 : 0}%`"></div>
            </div>
            <div class="flex gap-2">
                <button type="button" class="rounded-lg border border-zinc-600 px-3 py-2 text-sm font-semibold" x-on:click="rest += 15; total += 15">+15s</button>
                <button type="button" class="rounded-lg bg-lime px-3 py-2 text-sm font-bold text-ink" x-on:click="stop()">{{ __('Skip') }}</button>
            </div>
        </div>
    </div>
</div>
