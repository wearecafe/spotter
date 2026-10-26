<?php

namespace App\Livewire\Concerns;

use App\Jobs\DraftInboxItem;
use App\Models\InboxItem;
use App\Models\WorkoutExercise;
use App\Services\Chat;
use App\Services\ProgramScheduler;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * The coach's decisions on inbox items. Shared by the web inbox and the phone focus mode.
 * Every AI output is a draft: only these actions turn a draft into something the client sees.
 */
trait HandlesInboxItems
{
    /** @var array<int, string> edited replies keyed by inbox item id */
    public array $replies = [];

    /**
     * Pre-fill each reply box with its draft once the draft exists, without overwriting the coach's edits.
     */
    public function renderingHandlesInboxItems(): void
    {
        foreach ($this->items as $item) {
            if ($item->draft_reply !== null && ! array_key_exists($item->id, $this->replies)) {
                $this->replies[$item->id] = $item->draft_reply;
            }
        }
    }

    protected function findItem(int $id): InboxItem
    {
        $item = InboxItem::with('client')->findOrFail($id);
        Gate::authorize('manage-inbox-item', $item);

        return $item;
    }

    public function replyFor(InboxItem $item): string
    {
        return $this->replies[$item->id] ?? (string) $item->draft_reply;
    }

    /**
     * Send the (possibly edited) reply to the client and clear the item.
     */
    public function approve(int $id): void
    {
        $item = $this->findItem($id);
        $body = trim($this->replyFor($item));

        if ($body === '') {
            $this->addError("replies.$id", __('Write a reply first, or mark it done without replying.'));

            return;
        }

        if (mb_strlen($body) > 4000) {
            $this->addError("replies.$id", __('Keep replies under 4,000 characters.'));

            return;
        }

        app(Chat::class)->send(Auth::user(), $item->client, $body);

        $this->resolve($item);
        Flux::toast(variant: 'success', text: __('Sent to :name.', ['name' => str($item->client->name)->before(' ')]));
    }

    public function markDone(int $id): void
    {
        $this->resolve($this->findItem($id));
    }

    public function snooze(int $id): void
    {
        $item = $this->findItem($id);
        $item->update(['status' => 'snoozed', 'snoozed_until' => now()->addDay()->setTime(8, 0)]);

        $this->afterInboxChange($item);
        Flux::toast(text: __('Snoozed until tomorrow morning.'));
    }

    public function redraft(int $id): void
    {
        $item = $this->findItem($id);
        $item->update(['summary' => null, 'draft_reply' => null, 'suggestion' => null, 'drafted_by' => null]);
        unset($this->replies[$id]);

        DraftInboxItem::dispatch($item);
    }

    /**
     * Apply the suggested program change to the client's next workout.
     */
    public function applySuggestion(int $id): void
    {
        $item = $this->findItem($id);
        $suggestion = $item->suggestion;

        if (! $suggestion || ($suggestion['applied'] ?? false)) {
            return;
        }

        $target = WorkoutExercise::with('workout')->find($suggestion['workout_exercise_id']);

        if (! $target || Gate::denies('update', $target->workout) || $target->workout->isCompleted()) {
            Flux::toast(variant: 'warning', text: __('That workout has changed since the suggestion was made.'));

            return;
        }

        $target->update([
            $suggestion['field'] => $suggestion['field'] === 'sets' ? (int) $suggestion['to'] : $suggestion['to'],
            'change_reason' => $suggestion['reason'] ?: null,
        ]);

        // A one-off change to this session: program edits shouldn't undo it.
        app(ProgramScheduler::class)->customize($target->workout);

        $item->update(['suggestion' => array_merge($suggestion, ['applied' => true])]);
        Flux::toast(variant: 'success', text: __('Updated :exercise.', ['exercise' => $suggestion['exercise'] ?? __('the workout')]));
    }

    protected function resolve(InboxItem $item): void
    {
        $item->update(['status' => 'done', 'resolved_at' => now()]);
        unset($this->replies[$item->id]);

        $this->afterInboxChange($item);
    }

    /**
     * Hook for the page to move on to the next item.
     */
    protected function afterInboxChange(InboxItem $item): void {}
}
