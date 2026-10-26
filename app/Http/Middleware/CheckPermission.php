<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckPermission
{
    public function handle(Request $request, Closure $next, string $key)
    {
        $user = $request->user();
        if (!$user) {
            abort(403);
        }
        if (!$user->hasPermission($key)) {
            abort(403);
        }
        return $next($request);
    }
}





