<?php

use App\Enums\Role;
use App\Models\User;
use App\Services\InboxFeed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inbox:scan-missed', function (InboxFeed $feed) {
    $created = 0;

    User::query()->where('role', Role::Coach)->each(function (User $coach) use ($feed, &$created) {
        $created += $feed->scanMissedSessions($coach);
    });

    $this->info("Flagged {$created} clients with missed sessions.");
})->purpose('Add Coach Inbox items for clients who missed two or more sessions this week');

Schedule::command('inbox:scan-missed')->dailyAt('06:00');
