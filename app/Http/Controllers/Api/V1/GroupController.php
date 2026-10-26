<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\MessageStatus;
use App\Http\Controllers\Controller;
use App\Models\MessageGroup;
use App\Models\TelegramMessage;
use App\Queries\GroupList;
use App\Sending\GroupProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;

/**
 * Sends over the API, in the old response shapes. Reading never changes a
 * status any more (the old endpoints rewrote it on every GET).
 */
final class GroupController extends Controller
{
    /** GET /api/history?search= (and the old GET /api/index/mass) */
    public function history(Request $request, GroupList $list): JsonResponse
    {
        $search = $request->validate(['search' => ['nullable', 'string', 'max:200']])['search'] ?? null;

        $groups = $list->query($request->user(), ['search' => $search])
            ->with(['messages' => fn ($q) => $q->select('id', 'message_group_id', 'peer', 'message_text', 'status', 'error_key', 'sent_at', 'created_at', 'updated_at')])
            ->paginate(15)
            ->withQueryString();

        // The old field types: creator is the account owner's name, dates are "d.m.Y H:i" strings.
        $groups->through(function (MessageGroup $group): array {
            $lastSent = $group->messages->whereNotNull('sent_at')->sortByDesc('sent_at')->first();

            return array_merge($group->toArray(), [
                'type' => 'messages',
                'peers_count' => $group->messages->pluck('peer')->filter()->unique()->count(),
                'total_messages' => (int) $group->messages_count,
                'messages_status_count' => [
                    'pending' => (int) $group->pending_count,
                    'sent' => (int) $group->sent_count,
                    'failed' => (int) $group->failed_count,
                ],
                'text' => $group->message_text ?? '—',
                'creator' => $group->phone?->user?->name ?? '—',
                'phone_number' => $group->phone?->phone ?? '—',
                'started_at' => $group->created_at?->format('d.m.Y H:i') ?? '—',
                'last_sent_at' => $lastSent?->sent_at?->format('d.m.Y H:i') ?? 'Hali yuborilmagan',
            ]);
        });

        return response()->json(['status' => 'success', 'type' => 'history', 'data' => $groups]);
    }

    /** GET /api/index/infinity: repeating sends no longer exist. */
    public function infinity(Request $request): JsonResponse
    {
        return response()->json(['status' => 'success', 'type' => 'infinity', 'data' => new LengthAwarePaginator([], 0, 15)]);
    }

    /** GET /api/groups/{group} */
    public function show(Request $request, MessageGroup $group): JsonResponse
    {
        Gate::authorize('view', $group);
        $group->load(['catalogs', 'messages', 'phone.user', 'attachment', 'loop']);
        $messages = $group->messages;

        $peers = $messages->groupBy('peer')->map(fn ($rows, $peer) => [
            'peer' => $peer,
            'messages' => $rows->values(),
            'text' => $group->message_text,
            'total' => $rows->count(),
            'sent_count' => $rows->where('status', MessageStatus::Sent)->count(),
            'pending_count' => $rows->whereIn('status', [MessageStatus::Pending, MessageStatus::Sending])->count(),
            'failed_count' => $rows->where('status', MessageStatus::Failed)->count(),
            'canceled_count' => $rows->where('status', MessageStatus::Canceled)->count(),
            'created_at' => $rows->min('created_at')?->toDateTimeString(),
            'sent_at' => $rows->max('sent_at')?->toDateTimeString(),
        ])->values();

        return response()->json([
            'status' => 'success',
            'data' => [
                'group' => $group,
                'overview' => [
                    'total_messages' => $messages->count(),
                    'sent' => $messages->where('status', MessageStatus::Sent)->count(),
                    'pending' => $messages->whereIn('status', [MessageStatus::Pending, MessageStatus::Sending])->count(),
                    'failed' => $messages->where('status', MessageStatus::Failed)->count(),
                    'canceled' => $messages->where('status', MessageStatus::Canceled)->count(),
                    'started_at' => $messages->min('created_at')?->toDateTimeString(),
                    'finished_at' => $messages->max(fn (TelegramMessage $m) => $m->sent_at)?->toDateTimeString(),
                ],
                'peers' => $peers,
            ],
        ]);
    }

    /** POST /api/groups/{group}/cancel and POST /api/infinity-messages/{group}/cancel */
    public function cancel(Request $request, MessageGroup $group, GroupProgress $progress): JsonResponse
    {
        Gate::authorize('cancel', $group);

        if (! $group->status->isActive()) {
            return response()->json(['status' => 'success', 'message' => 'Group already finished or canceled']);
        }

        $progress->cancel($group, $request->user()->id);

        return response()->json(['status' => 'success', 'message' => 'Pending messages successfully canceled']);
    }
}
