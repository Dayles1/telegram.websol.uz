<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesPhone;
use App\Http\Controllers\Controller;
use App\Jobs\FetchChatHistory;
use App\Models\ChatLog;
use App\Models\ChatLogGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Copying a chat's history into the database, and reading it back. */
final class ChatLogController extends Controller
{
    use ResolvesPhone;

    /** POST /api/fetch-messages {phone_id|phone|telegram_user_id, peer, status?} */
    public function fetch(Request $request): JsonResponse
    {
        $data = $request->validate($this->phoneRules() + [
            'peer' => ['required', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:fetched,fetching,failed'],
        ]);

        $phone = $this->resolvePhone($request->user(), $data);
        if ($phone === null || ! $phone->canSend()) {
            return response()->json(['success' => false, 'message' => 'Phone not found or not yours.'], 422);
        }

        $group = ChatLogGroup::query()->firstOrNew(['user_phone_id' => $phone->id, 'peer' => $data['peer']]);
        if ($group->last_fetch_at?->gt(now()->subHour())) {
            return response()->json([
                'success' => false,
                'message' => 'You can fetch only once per hour.',
                'next_allowed_fetch_at' => $group->last_fetch_at->copy()->addHour()->toDateTimeString(),
            ], 429);
        }

        $group->fill(['status' => $data['status'] ?? 'fetched', 'last_fetch_at' => now()])->save();
        FetchChatHistory::dispatch($group->id);

        return response()->json([
            'success' => true,
            'message' => 'Job dispatched. Messages will be fetched shortly.',
            'url' => url('/api/chat-logs').'?'.http_build_query(['phone_id' => $phone->id, 'peer' => $data['peer']]),
        ]);
    }

    /** GET /api/chat-logs?phone_id=&peer= */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate($this->phoneRules() + ['peer' => ['required', 'string', 'max:255']]);

        $phone = $this->resolvePhone($request->user(), $data);
        if ($phone === null) {
            return response()->json(['success' => false, 'message' => 'Phone not found or not yours.'], 422);
        }

        $group = ChatLogGroup::query()->where('user_phone_id', $phone->id)->where('peer', $data['peer'])->first();
        if ($group === null) {
            return response()->json(['success' => false, 'message' => 'Chat not found.'], 404);
        }

        $logs = $group->logs()->orderByDesc('message_date')->paginate(50)->withQueryString();
        $logs->setCollection($logs->getCollection()->map(fn (ChatLog $log) => [
            'id' => $log->id,
            'message_id' => $log->message_id,
            'text' => $log->message_text,
            'caption' => $log->caption,
            'date' => ($log->message_date ?? $log->created_at)?->toDateTimeString(),
            'from_user_id' => $log->from_user_id,
            'from_username' => $log->from_username,
            'media_type' => $log->media_type,
            'media_file_name' => $log->media_file_name,
            'media_mime' => $log->media_mime,
            'media_size' => $log->media_size,
            'media_local_path' => $log->media_local_path ? url($log->media_local_path) : null,
            'thumb_local_path' => $log->thumb_local_path ? url($log->thumb_local_path) : null,
        ]));

        return response()->json(['success' => true, 'group' => $group->peer, 'chat_type' => $group->chat_type, 'messages' => $logs]);
    }
}
