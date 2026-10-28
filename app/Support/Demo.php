<?php

namespace App\Support;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/**
 * North Shore Strength: the fictional demo gym that can live in production
 * alongside real teams. Visitors reach it through /demo.
 */
final class Demo
{
    /** Every demo account's email is on this reserved domain (RFC 2606), so it can never belong to a real person. */
    public const DOMAIN = 'northshorestrength.example';

    /** Shared by /demo (building a missing demo gym) and spotter:demo:reset. */
    public const LOCK = 'spotter:demo:seed';

    /** True while the demo gym is being built with AI drafts allowed. */
    private static bool $seedingWithAi = false;

    public static function enabled(): bool
    {
        return (bool) config('spotter.demo');
    }

    public static function team(): ?Team
    {
        return Team::query()->where('is_demo', true)->first();
    }

    public static function teamId(): ?int
    {
        $id = Team::query()->where('is_demo', true)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Whether /demo is usable: turned on, and the demo gym has been built.
     */
    public static function available(): bool
    {
        return self::enabled() && self::teamId() !== null;
    }

    /**
     * The demo gym, building it first if it's turned on but hasn't been
     * built yet (a fresh deploy before the nightly rebuild has run).
     */
    public static function ensureTeam(): ?Team
    {
        if (! self::enabled()) {
            return null;
        }

        if ($team = self::team()) {
            return $team;
        }

        // Rule-based drafts, so the first visitor isn't left waiting on a dozen AI calls.
        Artisan::call('spotter:demo:reset', ['--if-missing' => true, '--without-ai' => true]);

        return self::team();
    }

    public static function isDemoTeam(?int $teamId): bool
    {
        return $teamId !== null && $teamId === self::teamId();
    }

    public static function isDemoUser(?User $user): bool
    {
        return $user !== null && ! $user->is_staff && self::isDemoTeam($user->team_id);
    }

    public static function isDemoEmail(?string $email): bool
    {
        return $email !== null && Str::endsWith(Str::lower(trim($email)), '@'.self::DOMAIN);
    }

    /**
     * Inbox drafts and program-file reading for the demo gym use Claude only
     * while it's being built: a handful of calls a night, never per visitor.
     */
    public static function mayUseAi(?int $teamId): bool
    {
        return self::$seedingWithAi || ! self::isDemoTeam($teamId);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function seeding(callable $callback, bool $withAi = true): mixed
    {
        $previous = self::$seedingWithAi;
        self::$seedingWithAi = $withAi;

        try {
            return $callback();
        } finally {
            self::$seedingWithAi = $previous;
        }
    }
}
