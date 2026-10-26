<?php

namespace App\Console\Commands\V3;

use App\Jobs\TelegramLogoutJob;
use App\Models\MessageGroup;
use App\Models\UserPhone;
use App\Services\Telegram\TelegramPeerMessagingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramSendBatchMessages extends Command
{
    protected $signature = 'telegram:send-batch-messages-v3 {groupId} {batchNo}';
    protected $description = 'Send telegram messages for given message group and batch';

    protected int $perMessageSpacingSeconds = 1;

    public function __construct(
        protected TelegramPeerMessagingService $telegramService
    ) {
        parent::__construct();
    }

    public function handle()
    {
        $groupId = (int) $this->argument('groupId');
        $batchNo = (int) $this->argument('batchNo');

        $group = null;
        $userPhone = null;

        try {
            $group = MessageGroup::find($groupId);

            if (!$group) {
                Log::error('batch_group_not_found', [
                    'group_id' => $groupId,
                    'batch_no' => $batchNo,
                ]);

                return self::FAILURE;
            }

            $pendingQuery = $group->messages()
                ->where('status', 'pending')
                ->where('batch_no', $batchNo)
                ->orderBy('send_at');

            if (!$pendingQuery->exists()) {
                return self::SUCCESS;
            }

            $userPhone = UserPhone::find($group->user_phone_id);

            if (!$userPhone) {
                $this->failAllPendingBatch($group, $batchNo, 'user_phone_not_found');
                $this->markGroupFailed($group);

                return self::FAILURE;
            }

            if (!$this->telegramService->initMadeline($userPhone)) {
                $this->failAllPendingBatch($group, $batchNo, 'session_invalid');
                $this->markGroupFailed($group);
                $this->dispatchLogoutJob($userPhone);

                return self::FAILURE;
            }

            $peerInspectionCache = [];
            $queuedMessages = $pendingQuery->get();

            foreach ($queuedMessages as $queuedMessage) {
                $message = $group->messages()
                    ->whereKey($queuedMessage->id)
                    ->first();

                if (!$message || $message->status !== 'pending') {
                    continue;
                }

                try {
                    if ($this->perMessageSpacingSeconds > 0) {
                        sleep($this->perMessageSpacingSeconds);
                    }

                    $peer = (string) $message->peer;

                    $inspect = $peerInspectionCache[$peer]
                        ??= $this->telegramService->inspectPeer($peer);

                    if (!($inspect['ok'] ?? false) || !($inspect['sendable'] ?? false)) {
                        $errorKey = $inspect['error_key'] ?? 'peer_invalid';

                        $this->markMessageFailed($message, $errorKey);
                        $this->failPeerPendingMessages($group, $peer, $errorKey);

                        if (($inspect['session_error'] ?? false) || $this->telegramService->isSessionErrorKey($errorKey)) {
                            $this->failAllPendingBatch($group, $batchNo, $errorKey);
                            $this->markGroupFailed($group);
                            $this->telegramService->cleanupSession($userPhone);
                            $this->dispatchLogoutJob($userPhone);
                            break;
                        }

                        continue;
                    }

                    $result = $this->telegramService->sendMessageToPeer(
                        $peer,
                        $group->message_text,
                        $inspect
                    );

                    if (($result['ok'] ?? false) === true) {
                        $message->update([
                            'status' => $result['status'] ?? 'sent',
                            'sent_at' => now(),
                            'telegram_message_id' => $result['telegram_message_id'],
                            'attempts' => 0,
                            'error_key' => null,
                        ]);

                        continue;
                    }

                    $errorKey = $result['error_key'] ?? 'unknown_error';

                    $this->markMessageFailed($message, $errorKey);
                    $this->failPeerPendingMessages($group, $peer, $errorKey);

                    if (!empty($result['session_error']) || $this->telegramService->isSessionErrorKey($errorKey)) {
                        $this->failAllPendingBatch($group, $batchNo, $errorKey);
                        $this->markGroupFailed($group);
                        $this->telegramService->cleanupSession($userPhone);
                        $this->dispatchLogoutJob($userPhone);
                        break;
                    }
                } catch (Throwable $e) {
                    $errorKey = $this->telegramService->mapErrorToKey($e->getMessage());

                    if ($errorKey === 'unknown_error') {
                        Log::error('telegram_unknown_error', [
                            'stage' => 'send_loop',
                            'group_id' => $group->id,
                            'batch_no' => $batchNo,
                            'peer' => (string) $message->peer,
                        ]);
                    }

                    $this->markMessageFailed($message, $errorKey);
                    $this->failPeerPendingMessages($group, (string) $message->peer, $errorKey);

                    if ($this->telegramService->isSessionErrorKey($errorKey)) {
                        $this->failAllPendingBatch($group, $batchNo, $errorKey);
                        $this->markGroupFailed($group);
                        $this->telegramService->cleanupSession($userPhone);
                        $this->dispatchLogoutJob($userPhone);
                        break;
                    }
                }
            }

            return self::SUCCESS;
        } finally {
            if ($group && $group->status !== 'failed') {
                $this->advanceGroupProgress($group, $batchNo);
            }
        }
    }

    private function markMessageFailed($message, string $errorKey): void
    {
        $message->increment('attempts');
        $message->refresh();

        $message->update([
            'status' => 'failed',
            'error_key' => $errorKey,
        ]);

        $this->logPeerFailure(
            $message->group,
            (string) $message->peer,
            $errorKey,
            'markMessageFailed',
            $message->id
        );
    }
    private function logPeerFailure(MessageGroup $group, string $peer, string $errorKey, string $stage, ?int $messageId = null): void
    {
        Log::warning('telegram_peer_failed', [
            'payload' => json_encode([
                'group_id' => $group->id,
                'peer' => $peer,
                'error_key' => $errorKey,
                'stage' => $stage,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function failPeerPendingMessages(MessageGroup $group, string $peer, string $errorKey): void
    {
        $updatedCount = $group->messages()
            ->where('peer', $peer)
            ->where('status', 'pending')
            ->update([
                'status' => 'failed',
                'error_key' => $errorKey,
            ]);

        if ($updatedCount > 0) {
            Log::warning('telegram_peer_bulk_failed', [
                'payload' => json_encode([
                    'group_id' => $group->id,
                    'batch_no' => $group->current_batch ?? null,
                    'peer' => $peer,
                    'error_key' => $errorKey,
                    'updated_count' => $updatedCount,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    private function failAllPendingBatch(MessageGroup $group, int $batchNo, string $errorKey): void
    {
        $group->messages()
            ->where('batch_no', $batchNo)
            ->where('status', 'pending')
            ->update([
                'status' => 'failed',
                'error_key' => $errorKey,
            ]);

        if ($errorKey === 'unknown_error') {
            Log::error('telegram_unknown_error', [
                'stage' => 'fail_all_pending_batch',
                'group_id' => $group->id,
                'batch_no' => $batchNo,
            ]);
        }
    }

    private function markGroupFailed(MessageGroup $group): void
    {
        $group->update([
            'status' => 'failed',
        ]);
    }

    private function dispatchLogoutJob(UserPhone $userPhone): void
    {
        TelegramLogoutJob::dispatch($userPhone->id);
    }

    private function advanceGroupProgress(MessageGroup $group, int $batchNo): void
    {
        try {
            $group->refresh();

            if ($group->status === 'failed') {
                return;
            }

            $group->increment('current_batch');
            $group->refresh();

            $totalMessages = $group->messages()->count();
            $failedMessages = $group->messages()->where('status', 'failed')->count();
            $pendingMessages = $group->messages()->where('status', 'pending')->count();

            $update = [];

            if ($group->status === 'pending' && $pendingMessages === 0) {
                $update['status'] = ($totalMessages > 0 && $failedMessages === $totalMessages)
                    ? 'failed'
                    : 'completed';
            }

            if ($group->status === 'pending' && !empty($update)) {
                $group->update($update);
            }
        } catch (Throwable $e) {
            $err = $this->telegramService->mapErrorToKey($e->getMessage());

            if ($err === 'unknown_error') {
                Log::error('telegram_unknown_error', [
                    'payload' => json_encode([
                        'stage' => 'advance_group_progress',
                        'group_id' => $group->id,
                        'error_key' => $err,
                        'exception' => [
                            'message' => $e->getMessage(),
                            'class' => get_class($e),
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                        ],
                        'trace' => $e->getTraceAsString(),
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                ]);
            }
        }
    }
}
