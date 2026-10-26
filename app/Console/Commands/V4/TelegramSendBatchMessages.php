<?php

namespace App\Console\Commands\V4;

use App\Application\Services\Telegram\TelegramErrorService;
use App\Jobs\TelegramLogoutJob;
use App\Models\MessageGroup;
use App\Models\User;
use App\Models\UserPhone;
use danog\MadelineProto\API;
use danog\MadelineProto\Settings\AppInfo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramSendBatchMessages extends Command
{
    protected $signature = 'telegram:send-batch-messages-v4 {groupId} {batchNo}';
    protected $description = 'Send telegram messages v4 using getFullDialogs + getInfo match';

    protected int $perMessageSpacingSeconds = 1;

    private ?API $madeline = null;
    private ?string $madelineStartErrorKey = null;
    private ?string $madelineStartErrorMessage = null;

    private ?string $dialogLoadErrorKey = null;
    private ?string $dialogLoadErrorMessage = null;

    public function handle(): int
    {
        $groupId = (int) $this->argument('groupId');
        $batchNo = (int) $this->argument('batchNo');

        Log::info('telegram_v4_command_start', [
            'group_id' => $groupId,
            'batch_no' => $batchNo,
            'started_at' => now()->toDateTimeString(),
        ]);

        $group = MessageGroup::find($groupId);

        if (!$group) {
            Log::error('telegram_v4_group_not_found', [
                'group_id' => $groupId,
                'batch_no' => $batchNo,
            ]);

            return self::FAILURE;
        }

        $pendingMessages = $group->messages()
            ->where('status', 'pending')
            ->where('batch_no', $batchNo)
            ->orderBy('send_at')
            ->get();

        if ($pendingMessages->isEmpty()) {
            return self::SUCCESS;
        }

        $userPhone = UserPhone::find($group->user_phone_id);

        if (!$userPhone) {
            $this->failAllPendingBatch($group, $batchNo, 'user_phone_not_found');
            $this->markGroupFailed($group);

            Log::warning('telegram_v4_user_phone_not_found', [
                'group_id' => $group->id,
                'batch_no' => $batchNo,
                'user_phone_id' => $group->user_phone_id,
            ]);

            return self::FAILURE;
        }

        $user = $group->phone?->user ?? User::find($group->user_id ?? null);

        $this->madeline = $this->madeline($userPhone->session_path);

        if (!$this->madeline) {
            $errorKey = $this->madelineStartErrorKey ?? 'session_invalid';
            $errorMessage = $this->madelineStartErrorMessage ?? 'Madeline start failed';

            Log::warning('telegram_v4_madeline_start_failed', [
                'group_id' => $group->id,
                'batch_no' => $batchNo,
                'user_phone_id' => $userPhone->id,
                'error_key' => $errorKey,
                'message' => $errorMessage,
            ]);

            $this->failAllPendingBatch($group, $batchNo, $errorKey);
            $this->markGroupFailed($group);

            if ($user) {
                TelegramLogoutJob::dispatch($userPhone->id, $user->id, $errorKey)
                    ->onQueue('telegramBot');
            }

            return self::FAILURE;
        }

        try {
            $dialogIds = $this->loadDialogIdsUnion(
                attempts: 4,
                delayMicros: 15000,
                stableRounds: 3
            );

            $dialogIndex = $this->buildDialogIdIndex($dialogIds);

            $this->logDialogIds($dialogIndex);

            Log::info('telegram_v4_dialogs_loaded', [
                'group_id' => $group->id,
                'batch_no' => $batchNo,
                'dialogs_count' => count($dialogIds),
            ]);

            if ($this->dialogLoadErrorKey && $this->isSessionErrorKey($this->dialogLoadErrorKey)) {
                Log::warning('telegram_v4_dialog_load_session_error', [
                    'group_id' => $group->id,
                    'batch_no' => $batchNo,
                    'error_key' => $this->dialogLoadErrorKey,
                    'message' => $this->dialogLoadErrorMessage,
                ]);

                $this->failAllPendingBatch($group, $batchNo, $this->dialogLoadErrorKey);
                $this->markGroupFailed($group);

                if ($user) {
                    TelegramLogoutJob::dispatch($userPhone->id, $user->id, $this->dialogLoadErrorKey)
                        ->onQueue('telegramBot');
                }

                return self::FAILURE;
            }

            foreach ($pendingMessages as $message) {
                if ($this->perMessageSpacingSeconds > 0) {
                    sleep($this->perMessageSpacingSeconds);
                }

                $rawPeer = trim((string) $message->peer);

                try {
                    $resolved = $this->resolvePeerFromDialogs($rawPeer, $dialogIndex);

                    if (!($resolved['ok'] ?? false) || !($resolved['sendable'] ?? false)) {
                        $errorKey = $resolved['error_key'] ?? 'not_member';

                        $this->markMessageFailed($message, $errorKey, 'peer_check');
                        $this->failPeerPendingMessages($group, $rawPeer, $errorKey);

                        if ($this->isSessionErrorKey($errorKey)) {
                            $this->failAllPendingBatch($group, $batchNo, $errorKey);
                            $this->markGroupFailed($group);

                            if ($user) {
                                TelegramLogoutJob::dispatch($userPhone->id, $user->id, $errorKey)
                                    ->onQueue('telegramBot');
                            }

                            break;
                        }

                        continue;
                    }

                    $sendResult = $this->sendMessageToPeer(
                        $resolved['resolved_peer'],
                        (string) $group->message_text
                    );

                    if (($sendResult['ok'] ?? false) === true) {
                        $message->update([
                            'status' => $sendResult['status'] ?? 'sent',
                            'sent_at' => now(),
                            'telegram_message_id' => $sendResult['telegram_message_id'],
                            'attempts' => 0,
                            'error_key' => null,
                        ]);

                        continue;
                    }

                    $errorKey = $sendResult['error_key'] ?? 'unknown_error';

                    $this->markMessageFailed($message, $errorKey, 'after_send');
                    $this->failPeerPendingMessages($group, $rawPeer, $errorKey);

                    if ($this->isSessionErrorKey($errorKey)) {
                        $this->failAllPendingBatch($group, $batchNo, $errorKey);
                        $this->markGroupFailed($group);

                        if ($user) {
                            TelegramLogoutJob::dispatch($userPhone->id, $user->id, $errorKey)
                                ->onQueue('telegramBot');
                        }

                        break;
                    }
                } catch (Throwable $e) {
                    $errorKey = TelegramErrorService::mapErrorToKey($e->getMessage());

                    Log::error('telegram_v4_send_loop_exception', [
                        'group_id' => $group->id,
                        'batch_no' => $batchNo,
                        'peer' => $rawPeer,
                        'error_key' => $errorKey,
                        'error_message' => $e->getMessage(),
                    ]);

                    $this->markMessageFailed($message, $errorKey, 'loop_exception');
                    $this->failPeerPendingMessages($group, $rawPeer, $errorKey);

                    if ($this->isSessionErrorKey($errorKey)) {
                        $this->failAllPendingBatch($group, $batchNo, $errorKey);
                        $this->markGroupFailed($group);

                        if ($user) {
                            TelegramLogoutJob::dispatch($userPhone->id, $user->id, $errorKey)
                                ->onQueue('telegramBot');
                        }

                        break;
                    }
                }
            }

            return self::SUCCESS;
        } finally {
            if ($group->status !== 'failed') {
                $this->advanceGroupProgress($group);
            }
        }
    }

    private function loadDialogIdsUnion(
        int $attempts = 10,
        int $delayMicros = 150000,
        int $stableRounds = 3
    ): array {
        $this->dialogLoadErrorKey = null;
        $this->dialogLoadErrorMessage = null;

        $union = [];
        $previousSnapshot = [];
        $stable = 0;

        for ($i = 1; $i <= $attempts; $i++) {
            try {
                $current = $this->madeline->getDialogIds();
            } catch (Throwable $e) {
                $message = $this->cleanError($e->getMessage());
                $errorKey = TelegramErrorService::mapErrorToKey($message);

                if ($this->isOperationCancelledError($message)) {
                    $errorKey = 'operation_canceled';
                }

                $this->dialogLoadErrorKey = $errorKey;
                $this->dialogLoadErrorMessage = $message;

                Log::warning('telegram_v4_dialog_ids_load_failed', [
                    'attempt' => $i,
                    'error_key' => $errorKey,
                    'error_message' => $message,
                ]);

                break;
            }

            if (!is_array($current)) {
                $current = [];
            }

            $current = array_values(array_unique(array_filter(array_map(
                fn ($value) => $this->normalizeValue($value),
                $current
            ))));

            $beforeUnionCount = count($union);

            foreach ($current as $id) {
                $union[$id] = true;
            }

            $afterUnionCount = count($union);

            $added = array_values(array_diff($current, $previousSnapshot));
            $removed = array_values(array_diff($previousSnapshot, $current));

            Log::info('telegram_v4_dialog_ids_attempt', [
                'attempt' => $i,
                'current_count' => count($current),
                'union_before' => $beforeUnionCount,
                'union_after' => $afterUnionCount,
                'new_in_union' => $afterUnionCount - $beforeUnionCount,
                'added_vs_prev' => $added,
                'removed_vs_prev' => $removed,
            ]);

            if ($afterUnionCount === $beforeUnionCount) {
                $stable++;
            } else {
                $stable = 0;
            }

            $previousSnapshot = $current;

            if ($stable >= $stableRounds) {
                break;
            }

            if ($delayMicros > 0 && $i < $attempts) {
                usleep($delayMicros);
            }
        }

        $result = array_keys($union);
        sort($result);

        Log::info('telegram_v4_dialog_ids_union_final', [
            'final_count' => count($result),
            'attempts_used' => $attempts,
            'error_key' => $this->dialogLoadErrorKey,
        ]);

        return $result;
    }

    private function isOperationCancelledError(string $message): bool
    {
        $message = strtolower($message);

        return str_contains($message, 'the operation was cancelled')
            || str_contains($message, 'operation was cancelled')
            || str_contains($message, 'operation cancelled');
    }

    private function buildDialogIdIndex(array $dialogIds): array
    {
        $byId = [];

        foreach ($dialogIds as $dialogId) {
            $id = $this->normalizeValue($dialogId);

            if ($id !== '') {
                $byId[$id] = true;
            }
        }

        return [
            'by_id' => $byId,
        ];
    }

    protected function madeline(string $sessionPath): ?API
    {
        $settings = new \danog\MadelineProto\Settings();

        $appInfo = new AppInfo();

        $apiId = (int) env('TELEGRAM_API_ID', 0);
        $apiHash = env('TELEGRAM_API_HASH', '');

        if (!$apiId || !$apiHash) {
            Log::warning('TELEGRAM_API_ID or TELEGRAM_API_HASH not set, assuming bot token session');
        }

        $appInfo->setApiId($apiId);
        $appInfo->setApiHash($apiHash);
        $settings->setAppInfo($appInfo);

        if (!$sessionPath || !File::exists($sessionPath)) {
            $this->madelineStartErrorKey = 'session_file_missing';
            $this->madelineStartErrorMessage = "Session file topilmadi: {$sessionPath}";
            return null;
        }

        try {
            $madeline = new API($sessionPath, $settings);
            $madeline->start();
            Log::info('telegram_v4_madeline_started', [
                'session_path' => $sessionPath,
                'started_at' => now()->toDateTimeString(),
            ]);
            $this->madelineStartErrorKey = null;
            $this->madelineStartErrorMessage = null;

            return $madeline;
        } catch (Throwable $e) {
            $this->madelineStartErrorKey = 'madeline_start_failed';
            $this->madelineStartErrorMessage = $e->getMessage();

            Log::error('telegram_v4_madeline_start_exception', [
                'session_path' => $sessionPath,
                'error_message' => $e->getMessage(),
                'exception_class' => get_class($e),
            ]);

            return null;
        }
    }

    private function logDialogIds(array $dialogIndex): void
    {
        $dialogIds = array_values(array_unique(array_filter(
            array_keys($dialogIndex['by_id'] ?? []),
            fn ($value) => $value !== ''
        )));

        Log::info('telegram_v4_dialog_ids', [
            'count' => count($dialogIds),
            'dialog_ids' => $dialogIds,
        ]);
    }

    private function resolvePeerFromDialogs(string $rawPeer, array $dialogIndex): array
    {
        $search = $this->normalizeValue($rawPeer);

        if ($search === '') {
            return $this->failResult('peer_invalid', 'peer bo‘sh');
        }

        try {
            $info = $this->madeline->getInfo($rawPeer);

            if (!is_array($info)) {
                $info = [];
            }

            $chatId = $this->normalizeValue($info['bot_api_id'] ?? $info['id'] ?? $rawPeer);
            $chatType = $info['type'] ?? $info['_'] ?? null;

            if ($chatId !== '' && isset($dialogIndex['by_id'][$chatId])) {
                return [
                    'ok' => true,
                    'sendable' => true,
                    'peer_type' => $chatType,
                    'resolved_peer' => $chatId,
                    'error_key' => null,
                    'reason' => 'dialog id matched',
                    'session_error' => false,
                ];
            }

            Log::warning('telegram_v4_peer_match_not_found', [
                'search_peer' => $rawPeer,
                'chat_id' => $chatId,
                'type' => $chatType,
            ]);

            return $this->failResult('not_member', 'peer topilmadi');
        } catch (Throwable $e) {
            Log::warning('telegram_v4_peer_getinfo_failed', [
                'search_peer' => $rawPeer,
                'error_message' => $e->getMessage(),
            ]);

            return $this->failResult('peer_not_found', 'peer topilmadi');
        }
    }

    private function resolveDialogPeer(mixed $dialog, string $fallbackId, string $rawPeer): string|int
    {
        if (is_array($dialog)) {
            if (!empty($dialog['bot_api_id'])) {
                return $dialog['bot_api_id'];
            }

            if (!empty($dialog['peer']['bot_api_id'])) {
                return $dialog['peer']['bot_api_id'];
            }

            if (!empty($dialog['id'])) {
                return $dialog['id'];
            }

            if (!empty($dialog['peer'])) {
                return $dialog['peer'];
            }
        }

        if ($fallbackId !== '') {
            return $fallbackId;
        }

        return $rawPeer;
    }

    private function sendMessageToPeer(string|int $peer, string $messageText): array
    {
        try {
            $response = $this->madeline->messages->sendMessage([
                'peer' => $peer,
                'message' => $messageText,
                'parse_mode' => 'HTML',
            ]);

            [$status, $telegramMessageId] = $this->extractSendResult($response);

            return [
                'ok' => true,
                'status' => $status,
                'telegram_message_id' => $telegramMessageId,
                'error_key' => null,
            ];
        } catch (Throwable $e) {
            $err = $this->cleanError($e->getMessage());
            $key = TelegramErrorService::mapErrorToKey($err);

            if ($this->isHtmlParseError($err)) {
                try {
                    $response = $this->madeline->messages->sendMessage([
                        'peer' => $peer,
                        'message' => $messageText,
                    ]);

                    [$status, $telegramMessageId] = $this->extractSendResult($response);

                    return [
                        'ok' => true,
                        'status' => $status,
                        'telegram_message_id' => $telegramMessageId,
                        'error_key' => null,
                    ];
                } catch (Throwable $e2) {
                    $err2 = $this->cleanError($e2->getMessage());
                    $key2 = TelegramErrorService::mapErrorToKey($err2);

                    return [
                        'ok' => false,
                        'status' => 'failed',
                        'telegram_message_id' => null,
                        'error_key' => $key2,
                    ];
                }
            }

            return [
                'ok' => false,
                'status' => 'failed',
                'telegram_message_id' => null,
                'error_key' => $key,
            ];
        }
    }

    private function extractSendResult(array $response): array
    {
        $telegramMessageId = null;
        $status = 'sent';

        if (($response['_'] ?? null) === 'updateShortSentMessage') {
            $telegramMessageId = $response['id'] ?? null;
        } elseif (($response['_'] ?? null) === 'updates') {
            foreach (($response['updates'] ?? []) as $update) {
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

        return [$status, $telegramMessageId];
    }

    private function failResult(string $errorKey, string $reason): array
    {
        return [
            'ok' => false,
            'sendable' => false,
            'peer_type' => null,
            'resolved_peer' => null,
            'error_key' => $errorKey,
            'reason' => $reason,
            'session_error' => false,
        ];
    }

    private function isGroupLikeType(?string $type): bool
    {
        return in_array($type, ['chat', 'group', 'supergroup', 'channel'], true);
    }

    private function isUserLikeType(?string $type): bool
    {
        return in_array($type, ['user', 'private'], true);
    }

    private function normalizeValue(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (!is_string($value)) {
            return '';
        }

        return mb_strtolower(trim($value));
    }

    private function markMessageFailed($message, string $errorKey, string $stage): void
    {
        $message->increment('attempts');
        $message->update([
            'status' => 'failed',
            'error_key' => $errorKey,
        ]);

        Log::warning('telegram_v4_message_failed', [
            'message_id' => $message->id,
            'peer' => $message->peer,
            'error_key' => $errorKey,
            'stage' => $stage,
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
            Log::warning('telegram_v4_peer_bulk_failed', [
                'group_id' => $group->id,
                'peer' => $peer,
                'error_key' => $errorKey,
                'updated_count' => $updatedCount,
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

        Log::warning('telegram_v4_fail_all_pending_batch', [
            'group_id' => $group->id,
            'batch_no' => $batchNo,
            'error_key' => $errorKey,
        ]);
    }

    private function markGroupFailed(MessageGroup $group): void
    {
        $group->update([
            'status' => 'failed',
        ]);
    }

    private function advanceGroupProgress(MessageGroup $group): void
    {
        try {
            $group->refresh();

            if ($group->status !== 'pending') {
                return;
            }

            $group->increment('current_batch');

            $totalMessages = $group->messages()->count();
            $failedMessages = $group->messages()->where('status', 'failed')->count();
            $pendingMessages = $group->messages()->where('status', 'pending')->count();

            if ($pendingMessages === 0) {
                $group->update([
                    'status' => ($totalMessages > 0 && $failedMessages === $totalMessages)
                        ? 'failed'
                        : 'completed',
                ]);
            }
        } catch (Throwable $e) {
            Log::error('telegram_v4_advance_group_failed', [
                'group_id' => $group->id,
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    private function isSessionErrorKey(string $errorKey): bool
    {
        return in_array($errorKey, [
            'auth_key_invalid',
            'phone_code_expired',
            'auth_key_unregistered',
            'session_revoked',
            'network_error',
            'madeline_not_initialized',
            // 'operation_canceled',
        ], true);
    }

    private function isHtmlParseError(string $err): bool
    {
        $e = strtolower($err);

        return str_contains($e, "can't parse entities")
            || str_contains($e, 'cannot parse entities')
            || str_contains($e, 'parse entities')
            || str_contains($e, 'entity')
            || str_contains($e, 'tag');
    }

    private function cleanError(string $err): string
    {
        return trim(preg_replace('/\s+/', ' ', $err));
    }
}