<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTelegramMessageTaskRequest;
use App\Jobs\Api\SendTelegramMessageJob;
use App\Models\Api\TelegramMessageTask;
use App\Models\UserPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramMessageTaskController extends Controller
{
    public function store(StoreTelegramMessageTaskRequest $request): JsonResponse
    {
        $sender = UserPhone::find($request->sender_phone_id);

        if (!$sender) {
            return response()->json([
                'message' => 'Sender topilmadi',
            ], 404);
        }

        if (empty($sender->session_path)) {
            return response()->json([
                'message' => 'Sender login qilinmagan. Avval Telegram session ni tiklash kerak.',
                'status' => TelegramMessageTask::STATUS_SENDER_NOT_LOGGED_IN,
            ], 422);
        }

        $task = TelegramMessageTask::create([
            'sender_phone_id' => $sender->id,
            'target_tg_id' => $request->target_tg_id,
            'target_phone' => $request->target_phone,
            'message_text' => $request->message,
            'status' => TelegramMessageTask::STATUS_PENDING,
            'meta' => [
                'request_ip' => $request->ip(),
            ],
        ]);

        SendTelegramMessageJob::dispatch($task->id)->onQueue('telegram');

        Log::info('Telegram message task yaratildi', [
            'task_id' => $task->id,
            'sender_phone_id' => $sender->id,
        ]);

        return response()->json([
            'message' => 'So‘rov qabul qilindi',
            'task_id' => $task->id,
            'status' => $task->status,
        ], 202);
    }

    public function show(int $id): JsonResponse
    {
        $task = TelegramMessageTask::findOrFail($id);

        return response()->json([
            'id' => $task->id,
            'sender_phone_id' => $task->sender_phone_id,
            'target_tg_id' => $task->target_tg_id,
            'target_phone' => $task->target_phone,
            'message_text' => $task->message_text,
            'status' => $task->status,
            'error_code' => $task->error_code,
            'error_message' => $task->error_message,
            'meta' => $task->meta,
            'sent_at' => $task->sent_at?->format('Y-m-d H:i:s'),
            'created_at' => $task->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $task->updated_at?->format('Y-m-d H:i:s'),
        ]);
    }
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $phoneIds = $user->phones()->pluck('id');

        $query = TelegramMessageTask::query()
            ->whereIn('sender_phone_id', $phoneIds)

            ->when(
                $request->filled('status'),
                fn($q) =>
                $q->where('status', $request->status)
            )

            ->when(
                $request->filled('sender_phone_id'),
                fn($q) =>
                $q->where('sender_phone_id', $request->sender_phone_id)
            )

            ->when($request->filled('search'), function ($q) use ($request) {
                $search = trim($request->search);

                $q->where(function ($sub) use ($search) {
                    $sub->where('id', $search)
                        ->orWhere('message_text', 'like', "%{$search}%")
                        ->orWhere('target_phone', 'like', "%{$search}%")
                        ->orWhere('target_tg_id', 'like', "%{$search}%")
                        ->orWhere('error_code', 'like', "%{$search}%")
                        ->orWhere('error_message', 'like', "%{$search}%");
                });
            })

            ->when(
                $request->filled('date_from'),
                fn($q) =>
                $q->whereDate('created_at', '>=', $request->date_from)
            )

            ->when(
                $request->filled('date_to'),
                fn($q) =>
                $q->whereDate('created_at', '<=', $request->date_to)
            )

            ->latest();

        $perPage = min(max((int) $request->integer('per_page', 15), 1), 100);

        $tasks = $query->paginate($perPage);

        return response()->json([
            'current_page' => $tasks->currentPage(),
            'last_page'    => $tasks->lastPage(),
            'per_page'     => $tasks->perPage(),
            'total'        => $tasks->total(),

            'data' => $tasks->getCollection()->map(function ($task) {
                return [
                    'id' => $task->id,
                    'sender_phone_id' => $task->sender_phone_id,
                    'target_tg_id' => $task->target_tg_id,
                    'target_phone' => $task->target_phone,
                    'message_text' => $task->message_text,
                    'status' => $task->status,
                    'error_code' => $task->error_code,
                    'error_message' => $task->error_message,
                    'sent_at' => $task->sent_at?->format('Y-m-d H:i:s'),
                    'created_at' => $task->created_at?->format('Y-m-d H:i:s'),
                ];
            }),
        ]);
    }
}
