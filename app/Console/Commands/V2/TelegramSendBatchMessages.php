<?php

namespace App\Console\Commands\V2;

use App\Application\Services\MadelineService;
use App\Application\Services\Telegram\TelegramErrorService;
use App\Models\MessageGroup;
use App\Models\UserPhone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class TelegramSendBatchMessages extends Command
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

            if (!$group || $group->status === 'canceled') {
                Log::info("Batch skipped (canceled group): {$groupId}");
                return self::SUCCESS;
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
                    $errorKey = TelegramErrorService::mapErrorToKey($err);
                    if ($errorKey === 'unknown_error') {
                        Log::warning(
                            "Unknown error for message (group_id={$groupId}, phone={$userPhone->phone}, batch_no={$batchNo}, peer={$message->peer}): {$err}"
                        );
                    }

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
                            // ->where('batch_no', $batchNo)
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
                                    'is_active' => false,
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
                    // $blacklistErrors = [
                    //     'user_banned',
                    //     'peer_not_found',
                    //     'chat_write_forbidden',
                    // ];
                    // if (in_array($errorKey, $blacklistErrors, true)) {
                    //     PeerBlock::firstOrCreate([
                    //         'user_phone_id' => $userPhone->id,
                    //         'peer' => $message->peer,
                    //     ], [
                    //         'error_key' => $storedError,
                    //     ]);

                    //     Log::warning(
                    //         "Peer blacklisted (user_phone_id={$userPhone->id}, peer={$message->peer}, error={$errorKey})"
                    //     );
                    // }
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
