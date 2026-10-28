<?php

namespace App\Http\Middleware;

use App\Support\Demo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Demo accounts are shared by every visitor, so their sign-in details and
 * account settings can't be changed. Everything else (programs, workouts,
 * messages, the team) is fair game and is rebuilt nightly.
 *
 * This covers plain HTTP requests (Fortify's routes live under user/). The
 * Livewire settings pages are guarded in AppServiceProvider.
 */
class ProtectDemoAccounts
{
    /** @var list<string> */
    private const PROTECTED = ['settings/*', 'user/*', 'email/verification-notification'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || ! $request->is(...self::PROTECTED) || ! Demo::isDemoUser($request->user())) {
            return $next($request);
        }

        $message = __('Account settings can’t be changed in the demo.');

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 403);
        }

        return back()->with('status', $message);
    }
}
