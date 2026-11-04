<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workout;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Clients moving a session to another day when life gets in the way: a work trip, a sick kid.
 *
 * A session can move to any day from today to the end of the week it was planned for, so
 * the week's training still happens that week. Its coach can turn this off per client.
 */
class Rescheduling
{
    public function __construct(protected InboxFeed $feed) {}

    public function canMove(User $client, Workout $workout): bool
    {
        return $client->isClient()
            && $workout->client_id === $client->id
            && $client->can_reschedule
            && ! $client->isArchived()
            && $workout->scheduled_on !== null
            && ! $workout->isStarted()
            && $this->options($workout)->isNotEmpty();
    }

    /**
     * Days the session can move to, from today to the end of the week it was planned for.
     *
     * @return Collection<int, CarbonInterface>
     */
    public function options(Workout $workout): Collection
    {
        if ($workout->scheduled_on === null) {
            return collect();
        }

        // Anchored to the week it was originally planned in, so a session can't drift week to week.
        $planned = Carbon::parse($workout->moved_from ?? $workout->scheduled_on);
        $from = $planned->copy()->startOfWeek()->max(today());
        $to = $planned->copy()->endOfWeek()->startOfDay();

        $days = collect();
        for ($day = $from->copy(); $day->lte($to); $day = $day->addDay()) {
            if (! $day->isSameDay($workout->scheduled_on)) {
                $days->push($day->copy());
            }
        }

        return $days;
    }

    public function move(User $client, Workout $workout, CarbonInterface|string $to): void
    {
        $to = Carbon::parse($to)->startOfDay();

        if (! $this->canMove($client, $workout) || ! $this->options($workout)->contains(fn (CarbonInterface $d) => $d->isSameDay($to))) {
            throw new InvalidArgumentException('That session can’t move to that day.');
        }

        $from = $workout->scheduled_on->copy();
        $original = $workout->moved_from ?? $from;

        $workout->forceFill([
            'scheduled_on' => $to,
            // Back on its planned day, it's no longer a moved session.
            'moved_from' => $to->isSameDay($original) ? null : $original,
        ])->save();

        $this->feed->sessionMoved($workout, $from);
    }
}
