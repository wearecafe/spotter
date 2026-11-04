<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workout;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Archiving takes a client out of the coach's day-to-day work (client list, inbox, Train,
 * missed-session alerts) and off the bill from the next month, without touching a single
 * record. Unarchiving puts them back exactly as they were.
 */
class ClientArchive
{
    public function archive(User $client, ?string $access = null, string $reason = 'coach'): void
    {
        if (! $client->isClient()) {
            throw new InvalidArgumentException('Only clients can be archived.');
        }

        $access ??= $client->coach?->archived_client_access ?? 'read_only';

        if (! array_key_exists($access, User::ARCHIVED_ACCESS)) {
            throw new InvalidArgumentException("Unknown archived access [{$access}].");
        }

        $client->forceFill([
            'archived_at' => $client->archived_at ?? now(),
            'archived_reason' => $reason,
            'archived_access' => $access,
        ])->save();
    }

    public function unarchive(User $client): void
    {
        $client->forceFill(['archived_at' => null, 'archived_reason' => null, 'archived_access' => null])->save();
    }

    /**
     * Change what an archived client can still do, without archiving them again.
     */
    public function setAccess(User $client, string $access): void
    {
        if (! $client->isArchived() || ! array_key_exists($access, User::ARCHIVED_ACCESS)) {
            throw new InvalidArgumentException('Only an archived client has archived access.');
        }

        $client->forceFill(['archived_access' => $access])->save();
    }

    /**
     * The last sign of life: signing in, or a session completed (including one a trainer logged
     * in person). A client who has done neither is measured from when they were added.
     */
    public function lastActiveAt(User $client): CarbonInterface
    {
        $lastWorkout = Workout::query()->where('client_id', $client->id)->max('completed_at');

        return collect([$client->last_seen_at, $lastWorkout ? Carbon::parse($lastWorkout) : null, $client->created_at])
            ->filter()
            ->sortDesc()
            ->first() ?? now();
    }

    /**
     * Archive a coach's clients who have been inactive longer than the coach's chosen window.
     * Returns how many were archived. Run daily.
     */
    public function autoArchive(User $coach): int
    {
        if (! $coach->isCoach() || ! $coach->auto_archive_days) {
            return 0;
        }

        $cutoff = now()->subDays($coach->auto_archive_days);
        $archived = 0;

        // Cheap first pass: anyone seen since the cutoff is active.
        $candidates = $coach->clients()
            ->whereNull('archived_at')
            ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $cutoff))
            ->where('created_at', '<', $cutoff)
            ->get();

        foreach ($candidates as $client) {
            if ($this->lastActiveAt($client)->lt($cutoff)) {
                $this->archive($client, $coach->archived_client_access, 'inactive');
                $archived++;
            }
        }

        return $archived;
    }
}
