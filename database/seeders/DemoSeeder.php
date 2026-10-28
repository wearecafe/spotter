<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Database\Seeder;

/**
 * Local development data: the North Shore Strength demo gym (sign in at
 * /demo with SPOTTER_DEMO=true), a second solo tenant and a Spotter staff
 * account for the backoffice (staff@example.com / password). Not run on deploy.
 *
 *   php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (Demo::teamId() === null) {
            $this->call(DemoTeamSeeder::class);
        }

        $solo = User::firstOrCreate(['email' => 'jordan.coach@example.com'], ['name' => 'Jordan Ellis Coaching', 'password' => 'password', 'role' => Role::Coach, 'email_verified_at' => now()]);
        if ($solo->wasRecentlyCreated) {
            User::factory()->count(4)->create(['role' => Role::Client, 'coach_id' => $solo->id, 'invitation_accepted_at' => now()]);
        }

        $staff = User::firstOrCreate(['email' => 'staff@example.com'], ['name' => 'Spotter Support', 'password' => 'password', 'role' => Role::Coach, 'email_verified_at' => now()]);
        $staff->forceFill(['is_staff' => true])->save();
    }
}
