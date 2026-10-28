<?php

namespace App\Console\Commands;

use App\Support\Demo;
use Database\Seeders\DemoTeamSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Deletes and rebuilds ONLY the fictional demo gym. Every other team is untouched.
 */
class ResetDemoTeam extends Command
{
    protected $signature = 'spotter:demo:reset
        {--force : Run without confirmation (for the scheduler)}
        {--if-missing : Only build it if there is no demo gym yet (used by /demo)}
        {--without-ai : Use rule-based inbox drafts even when ANTHROPIC_API_KEY is set}';

    protected $description = 'Rebuild the North Shore Strength demo gym with fresh data dated today';

    public function handle(): int
    {
        $team = Demo::team();

        if ($team !== null && ! $this->option('force') && ! $this->option('if-missing') && ! $this->confirm("Delete and rebuild {$team->name}? Other teams are not affected.", true)) {
            return self::FAILURE;
        }

        // The same lock /demo takes when it builds a missing demo gym, so a
        // visitor arriving mid-reset waits instead of building a second one.
        $built = Cache::lock(Demo::LOCK, 600)->block(120, function () {
            if ($this->option('if-missing') && Demo::teamId() !== null) {
                return false;
            }

            $files = DB::transaction(fn () => $this->deleteDemo());
            $this->deleteFiles($files);

            $seeder = app(DemoTeamSeeder::class);
            $seeder->useAi = ! $this->option('without-ai');
            $seeder->run();

            return true;
        });

        $this->info($built ? 'Demo gym rebuilt. Open /demo to explore it.' : 'The demo gym already exists.');

        return self::SUCCESS;
    }

    /**
     * Delete the demo gym, children before parents, so it works on any
     * database without relying on cascade order. That's the demo team, every
     * account on the reserved demo domain, and any workspace one of them owns
     * (a coach a visitor removed from the team gets a solo workspace of their own).
     *
     * @return list<string> Media files the demo had uploaded.
     */
    protected function deleteDemo(): array
    {
        $demoAccounts = DB::table('users')->where('email', 'like', '%@'.Demo::DOMAIN)->pluck('id')->all();
        $teams = DB::table('teams')->where('is_demo', true)->orWhereIn('owner_id', $demoAccounts)->pluck('id')->all();
        $users = DB::table('users')->whereIn('team_id', $teams)->orWhereIn('id', $demoAccounts)->pluck('id')->all();
        $clients = DB::table('users')->whereIn('id', $users)->where('role', 'client')->pluck('id')->all();

        if ($users === [] && $teams === []) {
            return [];
        }

        $files = [
            ...collect(DB::table('form_checks')->whereIn('client_id', $users)->pluck('video_path'))->filter()->all(),
            ...$this->jsonPaths(DB::table('check_ins')->whereIn('client_id', $users)->pluck('photos')),
            ...$this->jsonPaths(DB::table('messages')->whereIn('client_id', $users)->pluck('photos')),
            ...collect(DB::table('users')->whereIn('id', $users)->pluck('logo_path'))->filter()->all(),
        ];

        $workouts = DB::table('workouts')->whereIn('coach_id', $users)->orWhereIn('client_id', $users)->pluck('id')->all();
        $programs = DB::table('programs')->whereIn('coach_id', $users)->orWhereIn('client_id', $users)->pluck('id')->all();
        $days = DB::table('program_days')->whereIn('program_id', $programs)->pluck('id')->all();

        DB::table('form_checks')->whereIn('client_id', $users)->delete();
        DB::table('inbox_items')->whereIn('coach_id', $users)->orWhereIn('client_id', $users)->delete();
        DB::table('workout_exercises')->whereIn('workout_id', $workouts)->delete();
        DB::table('workouts')->whereIn('id', $workouts)->delete();
        DB::table('program_exercises')->whereIn('program_day_id', $days)->delete();
        DB::table('program_days')->whereIn('id', $days)->delete();
        DB::table('programs')->whereIn('id', $programs)->delete();
        DB::table('messages')->whereIn('coach_id', $users)->orWhereIn('client_id', $users)->orWhereIn('sender_id', $users)->delete();
        DB::table('check_ins')->whereIn('client_id', $users)->delete();
        DB::table('nutrition_logs')->whereIn('client_id', $users)->delete();
        DB::table('imports')->whereIn('coach_id', $users)->delete();
        DB::table('exercises')->whereIn('coach_id', $users)->delete();
        DB::table('team_invitations')->whereIn('team_id', $teams)->delete();
        // Staff audit entries are kept; the team foreign key goes null when the team is deleted.
        DB::table('password_reset_tokens')->whereIn('email', DB::table('users')->whereIn('id', $users)->pluck('email'))->delete();
        DB::table('sessions')->whereIn('user_id', $users)->delete();
        DB::table('teams')->whereIn('id', $teams)->update(['owner_id' => null]);
        DB::table('users')->whereIn('id', $clients)->delete();
        DB::table('users')->whereIn('id', $users)->delete();
        DB::table('teams')->whereIn('id', $teams)->delete();

        return $files;
    }

    /**
     * @param  iterable<string|null>  $columns
     * @return list<string>
     */
    protected function jsonPaths(iterable $columns): array
    {
        return collect($columns)
            ->flatMap(fn (?string $json) => $json ? (array) json_decode($json, true) : [])
            ->filter(fn ($path) => is_string($path) && $path !== '')
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $files
     */
    protected function deleteFiles(array $files): void
    {
        if ($files === []) {
            return;
        }

        try {
            Storage::disk(config('filesystems.media_disk'))->delete($files);
        } catch (Throwable $e) {
            // The data is already gone; a stray file in the bucket isn't worth failing the rebuild over.
            report($e);
        }
    }
}
