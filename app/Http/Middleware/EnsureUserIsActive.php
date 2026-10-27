<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // A disabled account loses access immediately, even with a valid token or session.
        if ($user && ! $user->is_active) {
            abort(403, 'This account is disabled');
        }

        return $next($request);
    }
}
