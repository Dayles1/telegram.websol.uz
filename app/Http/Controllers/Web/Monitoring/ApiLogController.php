<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Monitoring;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiLogResource;
use App\Models\ApiLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The API request log on the Monitoring -> API page: the list and one call with its bodies. */
final class ApiLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'endpoint' => ['nullable', 'string', 'max:191'],
            'user_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['ok', 'errors'])],
            'search' => ['nullable', 'string', 'max:200'],
        ]);

        $logs = ApiLog::query()
            ->with('user:id,name')
            ->select(['id', 'method', 'endpoint', 'path', 'user_id', 'status', 'duration_ms', 'ip', 'user_agent', 'created_at'])
            ->selectRaw('LEFT(request_body, 160) AS request_body')
            ->when($filters['endpoint'] ?? null, fn ($q, $endpoint) => $q->where('endpoint', $endpoint))
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when(($filters['status'] ?? null) === 'ok', fn ($q) => $q->where('status', '<', 400))
            ->when(($filters['status'] ?? null) === 'errors', fn ($q) => $q->where('status', '>=', 400))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('path', 'like', "%{$s}%")
                ->orWhere('request_body', 'like', "%{$s}%")
                ->orWhere('response_body', 'like', "%{$s}%")))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return response()->json([
            'logs' => ApiLogResource::collection($logs->getCollection()),
            'meta' => ['current_page' => $logs->currentPage(), 'last_page' => $logs->lastPage(), 'total' => $logs->total()],
            'endpoints' => ApiLog::query()->distinct()->orderBy('endpoint')->pluck('endpoint'),
            'users' => User::query()
                ->whereIn('id', ApiLog::query()->select('user_id')->whereNotNull('user_id')->distinct())
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function show(ApiLog $log): JsonResponse
    {
        return response()->json((new ApiLogResource($log->load('user:id,name')))->withBodies());
    }
}
