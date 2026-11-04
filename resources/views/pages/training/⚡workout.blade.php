<?php

use App\Models\FormCheck;
use App\Models\Workout;
use App\Services\InboxFeed;
use App\Services\Rescheduling;
use App\Services\TrainerSession;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
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

    /** @var array<int, array{note: string, pain: bool}> the client's note and pain flag, keyed by workout_exercise id */
    public array $notes = [];

    /** @var array<int, mixed> form-check uploads keyed by workout_exercise id */
    public array $videos = [];

    /** @var array<int, string> */
    public array $videoNotes = [];

    /**
     * Re-checked on every action: access can change while the page is open (reassignment, removal).
     */
    public function hydrate(): void
    {
        Gate::authorize('log', $this->workout);
    }

    public function mount(Workout $workout): void
    {
        Gate::authorize('log', $workout);

        $this->workout = $workout->load('items.exercise', 'items.formChecks', 'coach', 'loggedBy:id,name');
        $this->feedback = (string) $workout->client_feedback;

        foreach ($this->workout->items as $item) {
            $this->logs[$item->id] = $item->setsForLogging();
            $this->notes[$item->id] = ['note' => (string) $item->client_note, 'pain' => $item->pain];
        }
    }

    /**
     * What the client did the last time they did each exercise, e.g. "3 × 5 @ 75 kg".
     *
     * @return array<int, string> keyed by exercise id
     */
    #[Computed]
    public function lastTimes(): array
    {
        return app(TrainerSession::class)->lastTime($this->workout);
    }

    public string $moveTo = '';

    /**
     * Days this session can move to (today to the end of its week), if the client may move it.
     *
     * @return \Illuminate\Support\Collection<int, \Carbon\CarbonInterface>
     */
    #[Computed]
    public function moveOptions()
    {
        $rescheduling = app(Rescheduling::class);

        return $rescheduling->canMove(Auth::user(), $this->workout) ? $rescheduling->options($this->workout) : collect();
    }

    public function move(Rescheduling $rescheduling): void
    {
        $this->validate(['moveTo' => ['required', 'date']], attributes: ['moveTo' => __('day')]);

        try {
            $rescheduling->move(Auth::user(), $this->workout, $this->moveTo);
        } catch (\InvalidArgumentException) {
            $this->addError('moveTo', __('That session can’t move to that day.'));

            return;
        }

        $this->workout->refresh();
        $this->moveTo = '';
        unset($this->moveOptions);
        Flux::modal('move-session')->close();
        Flux::toast(variant: 'success', text: __('Moved to :day. Your coach has been told.', ['day' => $this->workout->scheduled_on->translatedFormat('l j M')]));
    }

    public function saveProgress(bool $notify = true): void
    {
        $this->validate([
            'logs.*.*.reps' => ['nullable', 'string', 'max:30'],
            'logs.*.*.load' => ['nullable', 'string', 'max:30'],
            'logs.*.*.rpe' => ['nullable', 'in:'.implode(',', self::RPE_OPTIONS)],
            'logs.*.*.done' => ['boolean'],
            'feedback' => ['nullable', 'string', 'max:2000'],
            'notes.*.note' => ['nullable', 'string', 'max:500'],
            'notes.*.pain' => ['boolean'],
        ], attributes: ['notes.*.note' => __('note')]);

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

            $note = trim((string) ($this->notes[$item->id]['note'] ?? ''));
            $pain = (bool) ($this->notes[$item->id]['pain'] ?? false);
            $newlyPainful = $pain && ! $item->pain;

            $item->update(['logged_sets' => $sets, 'client_note' => $note !== '' ? $note : null, 'pain' => $pain]);

            // The coach hears about pain straight away, not only when the workout is finished.
            if ($newlyPainful) {
                app(InboxFeed::class)->painReported($item->setRelation('workout', $this->workout));
            }
        }

        $this->workout->update(['client_feedback' => $this->feedback ?: null]);

        if ($notify) {
            Flux::toast(variant: 'success', text: __('Progress saved.'));
        }
    }

    public function complete(InboxFeed $feed): void
    {
        $this->saveProgress(notify: false);

        $this->workout->update(['completed_at' => now()]);
        $feed->workoutCompleted($this->workout->fresh());

        // Re-plan the coming weeks of the program from what was actually lifted.
        app(\App\Services\ProgramScheduler::class)->sessionChanged($this->workout);

        Flux::toast(variant: 'success', heading: __('Workout complete!'), text: __('Nice work. Your coach can see your results.'));
    }

    public function reopen(): void
    {
        $this->workout->update(['completed_at' => null]);
        app(\App\Services\ProgramScheduler::class)->sessionChanged($this->workout);
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
        <div class="mt-2 flex flex-wrap items-start justify-between gap-3">
            <flux:heading size="xl" level="1">{{ $workout->title }}</flux:heading>
            @if ($this->moveOptions->isNotEmpty())
                <flux:modal.trigger name="move-session">
                    <flux:button size="sm" icon="calendar-days" data-test="move-session">{{ __('Move to another day') }}</flux:button>
                </flux:modal.trigger>
            @endif
        </div>
        @if ($workout->week || $workout->moved_from)
            <flux:text class="mt-1">
                @if ($workout->week){{ __('Week :week', ['week' => $workout->week]) }}@endif
                @if ($workout->week && $workout->moved_from) · @endif
                @if ($workout->moved_from){{ __('Moved from :day', ['day' => $workout->moved_from->translatedFormat('D j M')]) }}@endif
            </flux:text>
        @endif
    </div>

    @if ($this->moveOptions->isNotEmpty())
        <flux:modal name="move-session" class="md:w-96">
            <form wire:submit="move" class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ __('Move this session') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('Pick another day this week. Your coach will see that you moved it.') }}</flux:text>
                </div>
                <flux:radio.group wire:model="moveTo" :label="__('New day')">
                    @foreach ($this->moveOptions as $day)
                        <flux:radio :value="$day->toDateString()" :label="$day->isToday() ? __('Today') : ($day->isTomorrow() ? __('Tomorrow') : $day->translatedFormat('l j M'))" />
                    @endforeach
                </flux:radio.group>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" data-test="confirm-move">{{ __('Move') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    @if ($workout->instructions)
        <flux:callout icon="chat-bubble-left-ellipsis">
            <flux:callout.heading>{{ __('Note from :coach', ['coach' => str($workout->coach->name)->before(' ')]) }}</flux:callout.heading>
            <flux:callout.text class="whitespace-pre-line">{{ $workout->instructions }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($workout->loggedBy)
        <flux:callout icon="clipboard-document-check" data-test="logged-by-trainer">
            <flux:callout.heading>{{ __('Logged by :name in your session', ['name' => str($workout->loggedBy->name)->before(' ')]) }}</flux:callout.heading>
            @if ($workout->session_notes)
                <flux:callout.text class="whitespace-pre-line">{{ $workout->session_notes }}</flux:callout.text>
            @endif
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
                    @if ($last = $this->lastTimes[$item->exercise_id] ?? null)
                        <flux:text size="sm" class="mt-0.5 text-zinc-500" data-test="last-time">{{ __('Last time: :sets', ['sets' => $last]) }}</flux:text>
                    @endif
                    @if ($item->notes)
                        <flux:text size="sm" class="mt-1 italic">{{ $item->notes }}</flux:text>
                    @endif
                </div>
                <div class="flex shrink-0 gap-1">
                    @if ($item->exercise->hasDemo())
                        <flux:modal.trigger :name="'how-to-'.$item->id">
                            <flux:button size="sm" variant="ghost" icon="play-circle">{{ __('How to') }}</flux:button>
                        </flux:modal.trigger>
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

            <div x-data="{ open: @js(filled($notes[$item->id]['note'] ?? null) || ($notes[$item->id]['pain'] ?? false)) }" class="flex flex-col gap-2">
                <button type="button" x-show="! open" x-on:click="open = true" class="self-start text-sm font-medium text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200" data-test="add-note">
                    + {{ __('Add a note for your coach') }}
                </button>
                <div x-cloak x-show="open" class="flex flex-col gap-2">
                    <flux:input size="sm" wire:model="notes.{{ $item->id }}.note" :aria-label="__('Note on :exercise', ['exercise' => $item->exercise->name])"
                        :placeholder="__('e.g. Eased off the weight, my knee was sore')" maxlength="500" />
                    <flux:checkbox wire:model="notes.{{ $item->id }}.pain" x-on:change="$wire.saveProgress(false)"
                        :label="__('This caused pain or discomfort')" :description="__('Your coach is told right away.')" data-test="pain-flag" />
                </div>
            </div>
        </flux:card>

        @if ($item->exercise->hasDemo())
            <flux:modal :name="'how-to-'.$item->id" class="w-full md:w-[34rem]">
                <div class="flex flex-col gap-4">
                    <flux:heading size="lg">{{ $item->exercise->name }}</flux:heading>
                    @if ($item->exercise->imageUrls())
                        <div class="grid grid-cols-2 gap-2">
                            @foreach ($item->exercise->imageUrls() as $i => $url)
                                <img src="{{ $url }}" loading="lazy" alt="{{ __(':name, :position position', ['name' => $item->exercise->name, 'position' => $i === 0 ? __('start') : __('end')]) }}" class="aspect-[4/3] w-full rounded-lg bg-zinc-100 object-cover">
                            @endforeach
                        </div>
                    @endif
                    @if ($item->exercise->instructionSteps())
                        <ol class="flex list-decimal flex-col gap-2 ps-5 text-[15px] leading-relaxed text-zinc-700 dark:text-zinc-300">
                            @foreach ($item->exercise->instructionSteps() as $step)
                                <li>{{ $step }}</li>
                            @endforeach
                        </ol>
                    @endif
                    @if ($item->exercise->video_url)
                        <flux:button icon="play-circle" :href="$item->exercise->video_url" target="_blank">{{ __('Watch demo video') }}</flux:button>
                    @endif
                </div>
            </flux:modal>
        @endif

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
