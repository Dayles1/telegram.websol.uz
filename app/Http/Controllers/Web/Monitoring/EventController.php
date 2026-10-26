<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Monitoring;

use App\Enums\EventKind;
use App\Enums\EventLevel;
use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Models\SystemEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** The journal: problems (folded by fingerprint) and plain events, with filters. */
final class EventController extends Controller
{
    public function index(): View
    {
        return view('monitoring.events');
    }

    public function data(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'level' => ['nullable', Rule::in(['problems', ...array_column(EventLevel::cases(), 'value')])],
            'kind' => ['nullable', Rule::enum(EventKind::class)],
            'state' => ['nullable', Rule::in(['open', 'resolved', 'all'])],
            'phone_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:200'],
        ]);
        $state = $filters['state'] ?? 'open';
        $level = $filters['level'] ?? 'problems';

        $events = SystemEvent::query()
            ->with(['phone:id,phone', 'user:id,name'])
            ->when($level === 'problems', fn ($q) => $q->problems())
            ->when($level !== 'problems', fn ($q) => $q->where('level', $level))
            ->when($filters['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
            ->when($state === 'open', fn ($q) => $q->whereNull('resolved_at'))
            ->when($state === 'resolved', fn ($q) => $q->whereNotNull('resolved_at'))
            ->when($filters['phone_id'] ?? null, fn ($q, $id) => $q->where('user_phone_id', $id))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('message', 'like', "%{$s}%")->orWhere('error_key', 'like', "%{$s}%")))
            ->latest('last_seen_at')
            ->paginate(30)
            ->withQueryString();

        return response()->json([
            'events' => EventResource::collection($events->getCollection()),
            'meta' => ['current_page' => $events->currentPage(), 'last_page' => $events->lastPage(), 'total' => $events->total()],
            'counts' => SystemEvent::query()->open()->problems()->selectRaw('kind, COUNT(*) AS total')->groupBy('kind')->pluck('total', 'kind'),
        ]);
    }

    public function show(SystemEvent $event): View
    {
        return view('monitoring.event', ['event' => (new EventResource($event->load(['phone:id,phone', 'user:id,name'])))->resolve()]);
    }

    public function resolve(SystemEvent $event): JsonResponse
    {
        $event->update(['resolved_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function resolveAll(Request $request): JsonResponse
    {
        $kind = $request->validate(['kind' => ['nullable', Rule::enum(EventKind::class)]])['kind'] ?? null;
        $count = SystemEvent::query()->open()->when($kind, fn ($q) => $q->where('kind', $kind))->update(['resolved_at' => now()]);

        return response()->json(['ok' => true, 'message' => __('monitoring.resolved_count', ['count' => $count])]);
    }
}
