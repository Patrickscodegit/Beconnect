<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user || $user->status !== 'active') {
            abort(403);
        }

        return $next($request);
    }
}
