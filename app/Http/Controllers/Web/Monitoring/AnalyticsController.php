<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Monitoring;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Queries\DeliveryStats;
use App\Support\ErrorText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Delivery over a period: volume per day/hour, why messages fail, who sends, which accounts suffer. */
final class AnalyticsController extends Controller
{
    public const PERIODS = ['today' => 0, '7d' => 6, '30d' => 29, '90d' => 89];

    public function index(): View
    {
        return view('monitoring.analytics', [
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period' => ['nullable', Rule::in(array_keys(self::PERIODS))],
            'user_id' => ['nullable', 'integer'],
            'phone_id' => ['nullable', 'integer'],
        ]);
        $days = self::PERIODS[$data['period'] ?? '7d'];
        $from = Carbon::now()->subDays($days)->startOfDay();
        $to = Carbon::now();

        $userId = isset($data['user_id']) ? (int) $data['user_id'] : null;
        $phoneId = isset($data['phone_id']) ? (int) $data['phone_id'] : null;

        $stats = DeliveryStats::between($from, $to)->forUser($userId)->forPhone($phoneId);
        $previous = DeliveryStats::between($from->copy()->subDays($days + 1), $from->copy()->subSecond())
            ->forUser($userId)->forPhone($phoneId);

        return response()->json([
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'totals' => $stats->totals(),
            'previous' => $previous->totals(),
            'series' => $stats->series(),
            'errors' => array_map(fn (array $e) => $e + ['text' => ErrorText::for($e['error_key'])], $stats->errors()),
            'users' => $stats->byUser(),
            'phones' => $stats->byPhone(),
        ]);
    }
}
