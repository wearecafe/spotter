<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out coaches and clients of a suspended team and archived clients who were locked out,
 * and records when people were last active.
 */
class TenantGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_staff && $user->team?->isSuspended()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', __('This account is paused. Please contact your coach or Spotter support.'));
        }

        // An archived client their coach locked out can't sign in until they're unarchived.
        if ($user && $user->isClient() && $user->isArchived() && $user->archived_access === 'locked') {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', __('Your coaching account is archived. Ask your coach to reactivate it.'));
        }

        if ($user && ($user->last_seen_at === null || $user->last_seen_at->lt(now()->subMinutes(15)))) {
            $user->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
