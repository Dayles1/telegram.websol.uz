<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\LoopStatus;
use App\Http\Controllers\Controller;
use App\Models\MessageGroup;
use App\Models\SendLoop;
use App\Sending\GroupProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Repeating sends created with POST /api/tg/send + repeat_every_minutes. */
final class LoopController extends Controller
{
    /** GET /api/loops?status=active|stopped|finished&per_page= */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::enum(LoopStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $loops = SendLoop::query()
            ->visibleTo($request->user())
            ->with(['phone:id,phone', 'attachment'])
            ->when($data['status'] ?? null, fn ($q, string $status) => $q->where('status', $status))
            ->latest('id')
            ->paginate((int) ($data['per_page'] ?? 15));

        return response()->json([
            'status' => 'success',
            'current_page' => $loops->currentPage(),
            'last_page' => $loops->lastPage(),
            'total' => $loops->total(),
            'data' => $loops->getCollection()->map(fn (SendLoop $loop) => $this->format($loop)),
        ]);
    }

    /** GET /api/loops/{loop}: the loop and its last 20 rounds. */
    public function show(SendLoop $loop): JsonResponse
    {
        Gate::authorize('view', $loop);
        $loop->load(['phone:id,phone', 'attachment']);

        $rounds = MessageGroup::query()
            ->where('send_loop_id', $loop->id)
            ->withDeliveryCounts()
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (MessageGroup $g) => [
                'group_id' => $g->id,
                'status' => $g->status->value,
                'total' => (int) $g->messages_count,
                'sent' => (int) $g->sent_count,
                'failed' => (int) $g->failed_count,
                'pending' => (int) $g->pending_count,
                'created_at' => $g->created_at?->format('Y-m-d H:i:s'),
                'finished_at' => $g->finished_at?->format('Y-m-d H:i:s'),
            ]);

        return response()->json(['status' => 'success', 'data' => $this->format($loop) + ['rounds' => $rounds]]);
    }

    /** POST /api/loops/{loop}/stop: no more rounds; the round running now is canceled. */
    public function stop(Request $request, SendLoop $loop, GroupProgress $progress): JsonResponse
    {
        Gate::authorize('stop', $loop);

        if (! $loop->isActive()) {
            return response()->json(['status' => 'success', 'message' => 'Loop already stopped']);
        }

        $progress->stopLoop($loop, $request->user()->id);

        return response()->json(['status' => 'success', 'message' => 'Loop stopped']);
    }

    /** @return array<string, mixed> */
    private function format(SendLoop $loop): array
    {
        return [
            'id' => $loop->id,
            'status' => $loop->status->value,
            'phone_id' => $loop->user_phone_id,
            'phone' => $loop->phone?->phone,
            'message' => $loop->message_text,
            'file' => $loop->attachment?->summary(),
            'catalog_ids' => $loop->catalog_ids ?? [],
            'peers' => $loop->peers ?? [],
            'every_minutes' => $loop->every_minutes,
            'max_rounds' => $loop->max_rounds,
            'rounds_done' => $loop->rounds_done,
            'next_run_at' => $loop->next_run_at?->format('Y-m-d H:i:s'),
            'stopped_at' => $loop->stopped_at?->format('Y-m-d H:i:s'),
            'stop_reason' => $loop->stop_reason,
            'created_at' => $loop->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
