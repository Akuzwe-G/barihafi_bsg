<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password
            && ! $request->routeIs('password.change', 'password.change.update', 'logout', 'password.reset', 'password.update')) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Change your initial password before continuing.'], 403);
            }

            return redirect()->route('password.change');
        }

        return $next($request);
    }
}
