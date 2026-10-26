<?php

use App\Enums\Role;
use App\Models\StaffAuditLog;
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

Artisan::command('spotter:staff {email} {--revoke}', function (string $email) {
    $user = User::firstWhere('email', strtolower($email));

    if (! $user) {
        $this->error("No account for {$email}. Register it first, then run this again.");

        return 1;
    }

    $user->forceFill(['is_staff' => ! $this->option('revoke')])->save();
    StaffAuditLog::create(['staff_id' => null, 'action' => $this->option('revoke') ? 'staff_revoked' : 'staff_granted', 'details' => ['email' => $user->email]]);

    $this->info($this->option('revoke') ? "{$user->email} is no longer Spotter staff." : "{$user->email} can now open /admin.");
})->purpose('Grant or revoke Spotter staff (backoffice) access');
