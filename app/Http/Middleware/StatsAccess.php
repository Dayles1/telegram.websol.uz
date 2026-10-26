<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * The statistics endpoints were public. Now they need either the dashboard
 * token (header X-Stats-Token, or ?token= for old dashboards; STATS_API_TOKEN in
 * .env) or a Sanctum token of a superadmin.
 */
final class StatsAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('telegram.stats_token');
        $given = (string) ($request->header('X-Stats-Token') ?? $request->query('token', ''));
        if ($expected !== '' && hash_equals($expected, $given)) {
            return $next($request);
        }

        $bearer = $request->bearerToken();
        $user = $bearer ? PersonalAccessToken::findToken($bearer)?->tokenable : null;
        if ($user !== null && method_exists($user, 'isSuperadmin') && $user->isSuperadmin()) {
            return $next($request);
        }

        return response()->json(['status' => 'error', 'message' => 'Forbidden.'], 403);
    }
}
