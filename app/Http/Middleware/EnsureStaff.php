<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The backoffice is for Spotter staff only. Everyone else gets a 404, so it isn't advertised.
 */
class EnsureStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->is_staff, 404);

        return $next($request);
    }
}
