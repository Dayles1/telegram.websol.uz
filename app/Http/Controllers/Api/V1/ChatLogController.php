<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\UserPhone;
use App\Models\ChatLogGroup;
use Illuminate\Http\Request;
use App\Jobs\FetchChatMessagesJob;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class ChatLogController extends Controller
{
    public function fetchMessages(Request $request)
    {
        $validated = $request->validate([
            'phone_id'         => 'nullable|exists:user_phones,id|required_without_all:phone,telegram_user_id',
            'phone'            => 'nullable|exists:user_phones,phone|required_without_all:phone_id,telegram_user_id',
            'telegram_user_id' => 'nullable|exists:user_phones,telegram_user_id|required_without_all:phone_id,phone',
            'peer'             => 'required|string',
            'status'           => 'nullable|string|in:fetched,fetching,failed',
        ]);

        try {
            $phoneId = $this->resolvePhoneId($validated, Auth::user());
            if (!$phoneId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Phone not found or not yours.',
                ], 422);
            }

            $peer = $validated['peer'];
            $status = $validated['status'] ?? 'fetched';

            // --- GROUP UPDATE / CREATE + LAST FETCH CHECK ---
            $group = ChatLogGroup::updateOrCreate(
                [
                    'user_phone_id' => $phoneId,
                    'peer' => $peer,
                ],
                [
                    // 'chat_type' => null, // $msg->_ hali yo'q, keyin update qilamiz
                    'status' => $status,
                ]
            );

            // Agar oxirgi fetch 1 soatdan kam bo‘lsa error qaytar
            if ($group->last_fetch_at && $group->last_fetch_at->gt(\Carbon\Carbon::now()->subHour())) {
                $nextFetch = $group->last_fetch_at->addHour();
                return response()->json([
                    'success' => false,
                    'message' => 'You can fetch only once per hour.',
                    'next_allowed_fetch_at' => $nextFetch->toDateTimeString(),
                ], 429);
            }

            // --- DISPATCH JOB ---
            FetchChatMessagesJob::dispatch($phoneId, $peer)
                ->onQueue('telegram');

            // --- UPDATE LAST FETCH TIME ---
            $group->update([
                'last_fetch_at' => \Carbon\Carbon::now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Job dispatched. Messages will be fetched shortly.',
                'url' => "http://telegram.websol.uz/api/v1/chat-logs?phone_id={$phoneId}&peer={$peer}",
            ]);
        } catch (\Throwable $e) {
            Log::error("Error dispatching fetch messages job: {$e->getMessage()}", ['trace' => $e->getTraceAsString()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to dispatch job.'
            ], 500);
        }
    }


    public function index(Request $request)
    {
        $validated = $request->validate([
            'phone_id'         => 'nullable|exists:user_phones,id|required_without_all:phone,telegram_user_id',
            'phone'            => 'nullable|exists:user_phones,phone|required_without_all:phone_id,telegram_user_id',
            'telegram_user_id' => 'nullable|exists:user_phones,telegram_user_id|required_without_all:phone_id,phone',
            'peer'             => 'required|string',
        ]);

        $phoneId = $this->resolvePhoneId($validated, Auth::user());
        if (!$phoneId) {
            return response()->json([
                'success' => false,
                'message' => 'Phone not found or not yours.',
            ], 422);
        }

        // eager load not required for paginate but useful in some cases
        $group = ChatLogGroup::where('user_phone_id', $phoneId)
            ->where('peer', $validated['peer'])
            ->first();

        if (!$group) {
            return response()->json([
                'success' => false,
                'message' => 'Chat not found.',
            ], 404);
        }

        // Paginate logs (sorted newest first)
        $chatLogs = $group->logs()->orderBy('message_date', 'desc')->paginate(50);
        // foreach ($chatLogs as $log) {
        //     $log->delete() ;
        // }

        // Transform each log into clean payload (keeps paginator meta)
        $transformed = $chatLogs->getCollection()->transform(function ($log) {
            // Ensure date formatting even if stored as string
            $date = null;
            if (!empty($log->message_date)) {
                try {
                    $date = \Carbon\Carbon::parse($log->message_date)->toDateTimeString();
                } catch (\Throwable $e) {
                    $date = (string)$log->message_date;
                }
            } else {
                $date = $log->created_at ? $log->created_at->toDateTimeString() : null;
            }

            return [
                'id' => $log->id,
                'message_id' => $log->message_id,
                'text' => $log->message_text,
                'caption' => $log->caption,
                'date' => $date,
                'from_user_id' => $log->from_user_id,
                'from_username' => $log->from_username,
                'media_type' => $log->media_type,
                'media_file_name' => $log->media_file_name,
                'media_mime' => $log->media_mime,
                'media_size' => $log->media_size,
                // make sure storage:link done or adjust to Storage::url(...)
                'media_local_path' => $log->media_local_path ? url($log->media_local_path) : null,
                'thumb_local_path' => $log->thumb_local_path ? url($log->thumb_local_path) : null,
                // 'provider_file_id' => $log->provider_file_id ? json_decode($log->provider_file_id, true) : null,
            ];
        });

        // replace paginator collection with transformed collection
        $chatLogs->setCollection($transformed);

        return response()->json([
            'success' => true,
            'group' => $group->peer,
            'chat_type' => $group->chat_type,
            'messages' => $chatLogs, // paginator with items + meta
        ]);
    }






    private function resolvePhoneId(array $validated, $user)
    {
        // 1. phone_id orqali
        if (!empty($validated['phone_id'])) {
            $phone = UserPhone::find($validated['phone_id']);

            if (!$phone || (!$user->hasRole('superadmin') && $phone->user_id != $user->id)) {
                return null;
            }

            if (!$this->isValidSession($phone)) {
                return null;
            }

            return $phone->id;
        }

        // 2. phone orqali
        if (!empty($validated['phone'])) {
            $query = UserPhone::where('phone', $validated['phone']);
            if (!$user->hasRole('superadmin')) {
                $query->where('user_id', $user->id);
            }
            $phone = $query->first();

            if (!$phone || !$this->isValidSession($phone)) {
                return null;
            }

            return $phone->id;
        }

        // 3. telegram_user_id orqali
        if (!empty($validated['telegram_user_id'])) {
            $query = UserPhone::where('telegram_user_id', $validated['telegram_user_id']);
            if (!$user->hasRole('superadmin')) {
                $query->where('user_id', $user->id);
            }
            $phone = $query->first();

            if (!$phone || !$this->isValidSession($phone)) {
                return null;
            }

            return $phone->id;
        }

        return null;
    }


    private function isValidSession(UserPhone $phone)
    {
        return $phone->session_path && file_exists($phone->session_path);
    }
}
