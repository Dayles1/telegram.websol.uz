<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, $role)
    {
        $user = $request->user();

        if (!$user || !$user->role || strtolower($user->role->name) !== strtolower($role)) {
            abort(403, 'Access denied.');
        }

        return $next($request);
    }
}
