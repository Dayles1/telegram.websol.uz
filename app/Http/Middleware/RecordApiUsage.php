<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Monitoring\ApiLogRecorder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Counts every API call per day, endpoint and user (table api_usage), after the
 * response is sent. The monitoring page shows which endpoints are alive.
 * Calls of the logged methods (POST) also keep their body and response (api_logs).
 */
final class RecordApiUsage
{
    public function __construct(private readonly ApiLogRecorder $log) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->log->shouldLog($request)) {
            $request->attributes->set('api_log', [
                'body' => $this->log->requestBody($request),
                'started_at' => microtime(true),
            ]);
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $route = $request->route();
        $endpoint = $route ? $route->uri() : 'unmatched:'.mb_substr($request->path(), 0, 150);

        try {
            $now = now();
            DB::table('api_usage')->upsert(
                [[
                    'day' => $now->toDateString(),
                    'method' => $request->method(),
                    'endpoint' => $endpoint,
                    'user_id' => (int) $request->user()?->getKey(),
                    'calls' => 1,
                    'errors' => $response->getStatusCode() >= 400 ? 1 : 0,
                    'last_ip' => $request->ip(),
                    'last_user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                    'last_called_at' => $now,
                ]],
                ['day', 'method', 'endpoint', 'user_id'],
                [
                    'calls' => DB::raw('calls + 1'),
                    'errors' => DB::raw('errors + '.($response->getStatusCode() >= 400 ? 1 : 0)),
                    'last_ip' => $request->ip(),
                    'last_user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                    'last_called_at' => $now,
                ],
            );
        } catch (Throwable $e) {
            Log::warning('API usage not recorded', ['exception' => $e->getMessage()]);
        }

        if ($logged = $request->attributes->get('api_log')) {
            $this->log->record($request, $response, $logged['body'], $logged['started_at']);
        }
    }
}
