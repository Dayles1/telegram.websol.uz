<?php

namespace App\Console\Commands\V2;

use App\Models\MessageGroup;
use App\Models\UserPhone;
use App\Services\MadelineService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SendMassMessagesCommand extends Command
{
    protected $signature = 'telegram:send-batch-messages {groupId} {batchNo}';
    protected $description = 'Send telegram messages for given message group and batch';

    protected int $perMessageSpacingSeconds = 1;
    protected int $maxAttempts = 1;

    public function __construct(protected MadelineService $madelineService)
    {
        parent::__construct();
    }

    public function handle()
    {
        $groupId = (int) $this->argument('groupId');
        $batchNo = (int) $this->argument('batchNo');

        $group = null;

        try {
            $group = MessageGroup::find($groupId);

            if (!$group) {
                Log::warning("MessageGroup topilmadi: id={$groupId}");
                return self::FAILURE;
            }

            $pendingQuery = $group->messages()
                ->where('status', 'pending')
                ->where('batch_no', $batchNo)
                ->orderBy('send_at');

            if (!$pendingQuery->exists()) {
                Log::info("Batch bo'yicha pending message topilmadi: group_id={$groupId}, batch_no={$batchNo}");
                return self::SUCCESS;
            }

            $userPhone = UserPhone::find($group->user_phone_id);

            if (!$userPhone) {
                Log::warning("UserPhone topilmadi: id={$group->user_phone_id}");

                $group->messages()
                    ->where('status', 'pending')
                    ->where('batch_no', $batchNo)
                    ->update([
                        'status' => 'failed',
                        'error_key' => 'user_phone_not_found',
                    ]);

                return self::FAILURE;
            }

            if (!$this->madelineService->validateAndStart($userPhone)) {
                Log::warning("Session ishlamayapti: user_phone_id={$userPhone->id}");

                $group->messages()
                    ->where('status', 'pending')
                    ->where('batch_no', $batchNo)
                    ->update([
                        'status' => 'failed',
                        'error_key' => 'session_invalid',
                    ]);

                return self::FAILURE;
            }

            $Madeline = $this->madelineService->getApi();
            $messages = $pendingQuery->get();

            foreach ($messages as $message) {
                try {
                    if ($this->perMessageSpacingSeconds > 0) {
                        sleep($this->perMessageSpacingSeconds);
                    }

                    $payload = [
                        'peer' => $message->peer,
                        'message' => $group->message_text,
                        'parse_mode' => 'HTML',
                    ];

                    $response = $Madeline->messages->sendMessage($payload);

                    $telegramMessageId = null;
                    $status = 'sent';

                    if (($response['_'] ?? null) === 'updateShortSentMessage') {
                        $telegramMessageId = $response['id'] ?? null;
                    } elseif (($response['_'] ?? null) === 'updates') {
                        foreach ($response['updates'] as $update) {
                            if (($update['_'] ?? null) === 'updateNewMessage') {
                                $telegramMessageId = $update['message']['id'] ?? null;
                                break;
                            }

                            if (($update['_'] ?? null) === 'updateNewScheduledMessage') {
                                $status = 'scheduled';
                                $telegramMessageId = $update['message']['id'] ?? null;
                                break;
                            }
                        }
                    }

                    $message->update([
                        'status' => $status,
                        'sent_at' => now(),
                        'telegram_message_id' => $telegramMessageId,
                        'attempts' => 0,
                        'error_key' => null,
                    ]);
                } catch (Throwable $e) {
                    $err = trim(preg_replace('/\s+/', ' ', $e->getMessage()));
                    $errorKey = $this->mapErrorToKey($err);

                    $storedError = $errorKey === 'unknown_error'
                        ? Str::limit($err, 250, '')
                        : $errorKey;

                    $message->increment('attempts');
                    $message->refresh();
                    $message->update([
                        'status' => 'failed',
                        'error_key' => $storedError,
                    ]);

                    $sessionErrors = [
                        'auth_key_invalid',
                        'phone_code_expired',
                        'auth_key_unregistered',
                        'session_revoked',
                        'network_error',
                    ];

                    if (in_array($errorKey, $sessionErrors, true)) {
                        Log::error(
                            "Session error (group_id={$groupId}, batch_no={$batchNo}, user_phone_id={$userPhone->id}): {$storedError}"
                        );

                        $group->messages()
                            ->where('status', 'pending')
                            ->where('batch_no', $batchNo)
                            ->update([
                                'status' => 'failed',
                                'error_key' => $storedError,
                            ]);

                        try {
                            Artisan::call('telegram:logout', ['userPhoneId' => $userPhone->id]);
                            Log::info("telegram:logout chaqirildi: userPhoneId={$userPhone->id}");
                        } catch (Throwable $ex) {
                            Log::warning("telegram:logout failed: " . $ex->getMessage());

                            try {
                                $sessionPath = $userPhone->session_path;

                                $userPhone->update([
                                    'session_path' => null,
                                    // 'is_active' => false,
                                ]);

                                if ($sessionPath && file_exists($sessionPath)) {
                                    if (is_dir($sessionPath)) {
                                        File::deleteDirectory($sessionPath);
                                    } else {
                                        @unlink($sessionPath);
                                    }
                                    Log::info("Fallback cleanup: session removed for userPhoneId={$userPhone->id}");
                                }

                            } catch (Throwable $inner) {
                                Log::warning("Fallback cleanup failed for userPhoneId={$userPhone->id}: " . $inner->getMessage());
                            }
                        }

                        break;
                    }
                    $group->messages()
                        ->where('peer', $message->peer)
                        ->where('status', 'pending')
                        ->update([
                            'status' => 'failed',
                            'error_key' => $storedError,
                        ]);

                    Log::error(
                        "Unknown/per-message error (group_id={$groupId}, batch_no={$batchNo}, peer={$message->peer}): {$storedError}"
                    );
                }
            }

            Log::info("Batch tugadi: group_id={$groupId}, batch_no={$batchNo}");

            return self::SUCCESS;
        } finally {
            if ($group) {
                $this->advanceGroupProgress($group, $batchNo);
            }
        }
    }

    private function isPermanentPeerError(string $errorKey): bool
    {
        return in_array($errorKey, [
            'chat_write_forbidden',
            'user_banned',
            'peer_not_found',
            'chat_guest_send_forbidden',
        ], true);
    }

    private function mapErrorToKey(string $err): string
    {
        $e = strtolower($err);

        if (preg_match('/flood[_ ]?wait[_ ]?(\d+)/i', $err, $matches)) {
            $seconds = $matches[1] ?? '';
            return $seconds ? "flood_wait_{$seconds}" : 'flood_wait';
        }

        if (preg_match('/slowmode[_ ]?wait[_ ]?(\d+)/i', $err, $matches)) {
            $seconds = $matches[1] ?? '';
            return $seconds ? "slowmode_wait_{$seconds}" : 'slowmode_wait';
        }

        if (str_contains($e, 'schedule_too_much') || str_contains($e, 'schedule too much') || str_contains($e, 'scheduled too many')) {
            return 'SCHEDULE_TOO_MUCH';
        }

        if (str_contains($e, 'chat_write_forbidden') || str_contains($e, 'chat write forbidden') || str_contains($e, 'chat admin required')) {
            return 'chat_write_forbidden';
        }

        if (str_contains($e, 'CHANNEL_INVALID') || str_contains($e, 'channel_invalid') || str_contains($e, 'channel invalid')) {
            return 'chat_write_forbidden';
        }

        if (str_contains($e, 'user_banned_in_channel') || str_contains($e, 'user is banned') || str_contains($e, 'user is blocked') || str_contains($e, 'bot was blocked')) {
            return 'user_banned';
        }

        if (str_contains($e, 'phone_code_expired') || str_contains($e, 'phone code expired')) {
            return 'phone_code_expired';
        }

        if (str_contains($e, 'auth_key_unregistered') || str_contains($e, 'session_revoked') || str_contains($e, 'auth_key_invalid')) {
            return 'auth_key_invalid';
        }

        if (preg_match('/timeout|timed out|connection.*reset|broken pipe|could not connect/i', $e)) {
            return 'network_error';
        }

        if (str_contains($e, 'peer is not present in the internal peer database') || str_contains($e, 'peer not found')) {
            return 'peer_not_found';
        }

        if (str_contains($e, 'chat_guest_send_forbidden')) {
            return 'chat_guest_send_forbidden';
        }

        return 'unknown_error';
    }
    private function advanceGroupProgress(MessageGroup $group, int $batchNo): void
    {
        try {
            $group->refresh();
            $group->increment('current_batch');
            $group->refresh();

            $totalMessages = $group->messages()->count();
            $failedMessages = $group->messages()->where('status', 'failed')->count();
            $pendingMessages = $group->messages()->where('status', 'pending')->count();

            $update = [];

            if ($pendingMessages === 0) {
                if ($totalMessages > 0 && $failedMessages === $totalMessages) {
                    $update['status'] = 'failed';
                } else {
                    $update['status'] = 'completed';
                }
            }

            if (!empty($update)) {
                $group->update($update);
                Log::info("Group status updated: group_id={$group->id}, status=" . ($update['status'] ?? ''));
            }
        } catch (Throwable $e) {
            Log::warning("advanceGroupProgress failed for group_id={$group->id}: " . $e->getMessage());
        }
    }
}