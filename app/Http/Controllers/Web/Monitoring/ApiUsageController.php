<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Monitoring;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/**
 * Which API endpoints are called, by whom, and which are never called (the
 * candidates for deletion). The request log below comes from ApiLogController.
 */
final class ApiUsageController extends Controller
{
    public function __invoke(Request $request): View
    {
        $days = (int) $request->integer('days', 30);
        $days = in_array($days, [7, 30, 90, 180], true) ? $days : 30;
        $since = now()->subDays($days - 1)->toDateString();

        $used = DB::table('api_usage')
            ->leftJoin('users', 'users.id', '=', 'api_usage.user_id')
            ->where('day', '>=', $since)
            ->groupBy('method', 'endpoint')
            ->selectRaw('method, endpoint, SUM(calls) AS calls, SUM(errors) AS errors, MAX(last_called_at) AS last_called_at')
            ->selectRaw("GROUP_CONCAT(DISTINCT COALESCE(users.name, 'no token') ORDER BY users.name SEPARATOR ', ') AS callers")
            ->orderByDesc('calls')
            ->get();

        $calledKeys = $used->map(fn ($r) => $r->method.' '.$r->endpoint)->flip();
        $never = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/'))
            ->flatMap(fn ($route) => collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->map(fn ($m) => $m.' '.$route->uri()))
            ->reject(fn (string $key) => $calledKeys->has($key))
            ->values();

        $tracking = DB::table('api_usage')->min('day');

        $logMethods = implode(', ', (array) config('telegram.api_log.methods'));
        $logDays = (int) config('telegram.api_log.keep_days');

        return view('monitoring.api-usage', compact('used', 'never', 'days', 'tracking', 'logMethods', 'logDays'));
    }
}
