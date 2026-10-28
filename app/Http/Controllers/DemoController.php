<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Demo;
use Database\Seeders\DemoTeamSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * The demo gym's sign-in page (/demo). It can only sign people into
 * accounts that belong to the demo gym; real teams never see it.
 */
class DemoController extends Controller
{
    public function show(): View
    {
        $team = Demo::ensureTeam();
        abort_if($team === null, 404);

        $emails = User::query()->where('team_id', $team->id)->pluck('email')->flip();

        $accounts = collect(DemoTeamSeeder::ACCOUNTS)
            ->filter(fn (array $account) => $emails->has(DemoTeamSeeder::email($account['key'])))
            ->values();

        return view('demo', ['team' => $team, 'accounts' => $accounts]);
    }

    public function login(Request $request): RedirectResponse
    {
        $teamId = Demo::enabled() ? Demo::teamId() : null;
        abort_if($teamId === null, 404);

        $key = $request->string('account')->toString();
        abort_unless(collect(DemoTeamSeeder::ACCOUNTS)->contains('key', $key), 404);

        $user = User::query()
            ->where('email', DemoTeamSeeder::email($key))
            ->where('team_id', $teamId)
            ->first();
        abort_if($user === null, 404);

        // Swap whoever is signed in for the demo account.
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    /**
     * Sign out of the demo and go to sign-up ("Start your own" in the demo banner).
     */
    public function leave(Request $request): RedirectResponse
    {
        if (Demo::isDemoUser($request->user())) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect()->route('register');
    }
}
