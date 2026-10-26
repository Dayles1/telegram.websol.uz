<?php

namespace App\Jobs\Api;

use App\Models\Api\TelegramMessageTask;
use App\Models\UserPhone;
use danog\MadelineProto\API;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendTelegramMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $taskId;

    public function __construct(int $taskId)
    {
        $this->taskId = $taskId;
    }

    public function handle(): void
    {
        Log::info('🚀 JOB STARTED', ['task_id' => $this->taskId]);

        $task = TelegramMessageTask::find($this->taskId);

        if (!$task) {
            Log::error('❌ TASK NOT FOUND', ['task_id' => $this->taskId]);
            return;
        }

        $task->update(['status' => 'processing']);

        $sender = UserPhone::find($task->sender_phone_id);

        if (!$sender) {
            $this->failTask($task, 'SENDER_NOT_FOUND', 'Sender yo‘q');
            return;
        }

        if (empty($sender->session_path)) {
            $this->failTask($task, 'SENDER_NOT_LOGGED_IN', 'Session yo‘q');
            return;
        }

        $api = $this->startMadeline($sender, $task);

        if (!$api) {
            $this->failTask($task, 'MADLINE_START_FAILED', 'Madeline start bo‘lmadi');
            return;
        }

        if (!empty($task->target_tg_id)) {
            try {
                $peer = $this->resolvePeerByTgId($api, (string) $task->target_tg_id);

                if ($peer) {
                    $api->messages->sendMessage([
                        'peer' => $peer,
                        'message' => $task->message_text,
                        'parse_mode' => 'HTML',
                    ]);

                    $task->update([
                        'status' => TelegramMessageTask::STATUS_SENT,
                        'sent_at' => now(),
                    ]);

                    Log::info('✅ MESSAGE SENT BY TG_ID', [
                        'task_id' => $task->id,
                        'target_tg_id' => $task->target_tg_id,
                    ]);

                    return;
                }

                Log::warning('⚠️ TG_ID peer topilmadi', [
                    'task_id' => $task->id,
                    'target_tg_id' => $task->target_tg_id,
                ]);
            } catch (Throwable $e) {
                Log::warning('⚠️ TG_ID send failed, fallback to phone', [
                    'task_id' => $task->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (!empty($task->target_phone)) {
            try {
                $peer = $this->resolvePeerByPhone($api, $task->target_phone);

                if (!$peer) {
                    $this->failTask($task, 'TARGET_PHONE_NOT_FOUND', 'Telefon bo‘yicha contact topilmadi');
                    return;
                }

                $api->messages->sendMessage([
                    'peer' => $peer,
                    'message' => $task->message_text,
                    'parse_mode' => 'HTML',
                ]);

                $task->update([
                    'status' => TelegramMessageTask::STATUS_SENT,
                    'sent_at' => now(),
                ]);

                Log::info('✅ MESSAGE SENT BY PHONE', [
                    'task_id' => $task->id,
                    'target_phone' => $task->target_phone,
                ]);

                return;
            } catch (Throwable $e) {
                $this->failTask($task, 'SEND_FAILED', $e->getMessage());
                return;
            }
        }

        $this->failTask($task, 'TARGET_NOT_FOUND', 'Na tg_id, na phone topilmadi');
    }

    private function startMadeline(UserPhone $sender, TelegramMessageTask $task): ?API
    {
        try {
            $api = new API($sender->session_path);
            $api->start();

            Log::info('MadelineProto ishga tushdi', [
                'task_id' => $task->id,
                'sender_phone_id' => $sender->id,
            ]);

            return $api;
        } catch (Throwable $e) {
            Log::error('MadelineProto start bo‘lmadi', [
                'task_id' => $task->id,
                'sender_phone_id' => $sender->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function resolvePeerByTgId(API $api, string $tgId): mixed
    {
        try {
            $info = $api->getInfo($tgId);

            return $info['InputPeer'] ?? null;
        } catch (Throwable $e) {
            Log::warning('TG_ID resolve failed', [
                'tg_id' => $tgId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function resolvePeerByPhone(API $api, string $phone): mixed
    {
        $phone = $this->normalizePhone($phone);

        try {
            $resolved = $api->contacts->resolvePhone([
                'phone' => $phone,
            ]);

            return $resolved['users'][0] ?? null;
        } catch (Throwable $e) {
            Log::warning('Phone resolve failed', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);

        if (!$digits) {
            return $phone;
        }

        return '+' . $digits;
    }

    private function failTask(TelegramMessageTask $task, string $code, string $message): void
    {
        $task->update([
            'status' => TelegramMessageTask::STATUS_FAILED,
            'error_code' => $code,
            'error_message' => $message,
        ]);

        Log::warning('Telegram task failed', [
            'task_id' => $task->id,
            'code' => $code,
            'message' => $message,
        ]);
    }
}