<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Models\MessageTask;
use App\Models\UserPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Single 1-to-1 messages for external systems. The caller may only send from
 * its own accounts (the old endpoint accepted any sender_phone_id).
 */
final class MessageTaskController extends Controller
{
    /** POST /api/telegram/single-messages {sender_phone_id, message, target_tg_id?, target_phone?} */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sender_phone_id' => ['required', 'integer'],
            'message' => ['required', 'string', 'max:4096'],
            'target_tg_id' => ['nullable', 'string', 'max:32', 'required_without:target_phone'],
            'target_phone' => ['nullable', 'string', 'max:32', 'required_without:target_tg_id'],
        ]);

        $sender = UserPhone::query()->find($data['sender_phone_id']);
        if ($sender === null || Gate::denies('use', $sender)) {
            return response()->json(['message' => 'Sender topilmadi'], 404);
        }
        if (! $sender->canSend()) {
            return response()->json(['message' => 'Sender login qilinmagan.', 'status' => 'sender_not_logged_in'], 422);
        }

        $task = MessageTask::query()->create([
            'sender_phone_id' => $sender->id,
            'target_tg_id' => $data['target_tg_id'] ?? null,
            'target_phone' => $data['target_phone'] ?? null,
            'message_text' => $data['message'],
            'status' => TaskStatus::Pending,
            'meta' => ['request_ip' => $request->ip(), 'user_id' => $request->user()->id],
        ]);

        return response()->json(['message' => 'So‘rov qabul qilindi', 'task_id' => $task->id, 'status' => $task->status->value], 202);
    }

    /** GET /api/telegram/single-messages/{task} */
    public function show(MessageTask $task): JsonResponse
    {
        Gate::authorize('view', $task);

        return response()->json($this->format($task) + ['meta' => $task->meta, 'updated_at' => $task->updated_at?->format('Y-m-d H:i:s')]);
    }

    /** GET /api/telegram/single-messages?status=&sender_phone_id=&search=&date_from=&date_to=&per_page= */
    public function index(Request $request): JsonResponse
    {
        $phoneIds = UserPhone::query()->ownedBy($request->user())->pluck('id');
        $search = trim((string) $request->input('search'));

        $tasks = MessageTask::query()
            ->whereIn('sender_phone_id', $phoneIds)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('sender_phone_id'), fn ($q) => $q->where('sender_phone_id', $request->integer('sender_phone_id')))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('message_text', 'like', "%{$search}%")
                ->orWhere('target_phone', 'like', "%{$search}%")
                ->orWhere('target_tg_id', 'like', "%{$search}%")
                ->orWhere('error_code', 'like', "%{$search}%")
                ->when(ctype_digit($search), fn ($id) => $id->orWhere('id', (int) $search))))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('date_to')))
            ->latest()
            ->paginate(min(max($request->integer('per_page', 15), 1), 100));

        return response()->json([
            'current_page' => $tasks->currentPage(),
            'last_page' => $tasks->lastPage(),
            'per_page' => $tasks->perPage(),
            'total' => $tasks->total(),
            'data' => $tasks->getCollection()->map(fn (MessageTask $t) => $this->format($t)),
        ]);
    }

    /** @return array<string, mixed> */
    private function format(MessageTask $task): array
    {
        return [
            'id' => $task->id,
            'sender_phone_id' => $task->sender_phone_id,
            'target_tg_id' => $task->target_tg_id,
            'target_phone' => $task->target_phone,
            'message_text' => $task->message_text,
            'status' => $task->status->value,
            'error_code' => $task->error_code,
            'error_message' => $task->error_message,
            'sent_at' => $task->sent_at?->format('Y-m-d H:i:s'),
            'created_at' => $task->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
