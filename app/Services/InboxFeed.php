<?php

namespace App\Services;

use App\Jobs\DraftInboxItem;
use App\Models\CheckIn;
use App\Models\FormCheck;
use App\Models\InboxItem;
use App\Models\Message;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutExercise;
use App\Support\Load;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Turns client activity into Coach Inbox items. Each new item is drafted in the background.
 */
class InboxFeed
{
    public function checkInSubmitted(CheckIn $checkIn): ?InboxItem
    {
        return $this->add($checkIn->client, 'check_in', $checkIn, __('Weekly check-in'));
    }

    public function formCheckUploaded(FormCheck $formCheck): ?InboxItem
    {
        $exercise = $formCheck->workoutExercise->exercise->name;

        return $this->add($formCheck->client, 'form_check', $formCheck, __('Form check: :exercise', ['exercise' => $exercise]));
    }

    /**
     * A completed workout can raise feedback worth answering and new personal records.
     */
    public function workoutCompleted(Workout $workout): void
    {
        // Reopening and completing again must not raise the same items twice.
        if ($workout->reported_at !== null) {
            return;
        }

        $workout->update(['reported_at' => now()]);
        $client = $workout->client;

        if (filled($workout->client_feedback)) {
            $this->add($client, 'feedback', $workout, __('Feedback on :title', ['title' => $workout->title]));
        }

        foreach ($workout->items()->with('exercise')->get() as $item) {
            $record = $this->newRecord($item, $client);

            if ($record) {
                $this->add($client, 'pr', $item, __('New best: :exercise', ['exercise' => $item->exercise->name]),
                    __(':exercise: :new, up from :old.', ['exercise' => $item->exercise->name, 'new' => $record['new'], 'old' => $record['old']]));
            }
        }
    }

    public function messageReceived(Message $message): ?InboxItem
    {
        $client = $message->client;

        // One open message item per client: new messages refresh it instead of piling up.
        $existing = InboxItem::query()
            ->where('coach_id', $client->coach_id)
            ->where('client_id', $client->id)
            ->where('type', 'message')
            ->where('status', '!=', 'done')
            ->first();

        if ($existing) {
            $existing->update([
                'subject_type' => $message->getMorphClass(),
                'subject_id' => $message->id,
                'headline' => Str::limit($message->body, 80),
                'status' => 'open',
                'snoozed_until' => null,
            ]);
            DraftInboxItem::dispatch($existing);

            return $existing;
        }

        return $this->add($client, 'message', $message, Str::limit($message->body, 80));
    }

    /**
     * Flag clients who missed two or more sessions in the past week. Run daily.
     */
    public function scanMissedSessions(User $coach): int
    {
        $created = 0;

        foreach ($coach->clients()->get() as $client) {
            $missed = $client->assignedWorkouts()
                ->whereNull('completed_at')
                ->whereBetween('scheduled_on', [today()->subDays(7), today()->subDay()])
                ->count();

            $alreadyFlagged = InboxItem::query()
                ->where('client_id', $client->id)
                ->where('type', 'missed')
                ->where('created_at', '>=', now()->subDays(7))
                ->exists();

            if ($missed >= 2 && ! $alreadyFlagged) {
                $this->add($client, 'missed', null, __('Missed :count sessions this week', ['count' => $missed]));
                $created++;
            }
        }

        return $created;
    }

    /**
     * Is this exercise's heaviest completed set a new best for the client?
     *
     * @return array{new: string, old: string}|null
     */
    public function newRecord(WorkoutExercise $item, User $client): ?array
    {
        $best = $this->heaviestSet($item->logged_sets ?? []);

        if ($best === null) {
            return null;
        }

        $previous = WorkoutExercise::query()
            ->where('exercise_id', $item->exercise_id)
            ->where('id', '!=', $item->id)
            ->whereHas('workout', fn ($q) => $q->where('client_id', $client->id)->whereNotNull('completed_at'))
            ->get()
            ->map(fn (WorkoutExercise $w) => $this->heaviestSet($w->logged_sets ?? []))
            ->filter()
            ->sortByDesc('kg')
            ->first();

        // A first-ever lift isn't a record worth a notification.
        if ($previous === null || $best['kg'] <= $previous['kg'] + 0.01) {
            return null;
        }

        return ['new' => $best['load'], 'old' => $previous['load']];
    }

    /**
     * @param  array<int, array<string, mixed>>  $sets
     * @return array{kg: float, load: string}|null
     */
    protected function heaviestSet(array $sets): ?array
    {
        return collect($sets)
            ->filter(fn ($s) => ($s['done'] ?? false) && filled($s['load'] ?? null))
            ->map(fn ($s) => ['kg' => Load::toKg($s['load']), 'load' => (string) $s['load']])
            ->filter(fn ($s) => $s['kg'] !== null && $s['kg'] > 0)
            ->sortByDesc('kg')
            ->first();
    }

    protected function add(User $client, string $type, ?Model $subject, string $headline, ?string $summary = null): ?InboxItem
    {
        if (! $client->coach_id) {
            return null;
        }

        $item = InboxItem::create([
            'coach_id' => $client->coach_id,
            'client_id' => $client->id,
            'type' => $type,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'priority' => InboxItem::PRIORITY[$type] ?? 50,
            'headline' => $headline,
            'summary' => $summary,
        ]);

        DraftInboxItem::dispatch($item);

        return $item;
    }
}
