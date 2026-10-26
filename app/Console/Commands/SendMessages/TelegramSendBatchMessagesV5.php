<?php

namespace App\Console\Commands\SendMessages;

use App\Application\Services\Telegram\TelegramErrorService;
use App\Jobs\TelegramLogoutJob;
use App\Models\MessageGroup;
use App\Models\Peer;
use App\Models\User;
use App\Models\UserPhone;
use danog\MadelineProto\API;
use danog\MadelineProto\Settings\AppInfo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramSendBatchMessagesV5 extends Command
{
    protected $signature = 'telegram:send-batch-messages-v5 {groupId} {batchNo}';
    protected $description = 'Send telegram messages using cached peers first, getInfo only when needed';

    protected int $perMessageSpacingSeconds = 1;

    // 7 kunda soft refresh, 20 kunda hard refresh
    protected int $peerSoftRefreshDays = 7;
    protected int $peerHardRefreshDays = 20;

    private ?API $madeline = null;
    private ?string $madelineStartErrorKey = null;
    private ?string $madelineStartErrorMessage = null;

    private ?string $dialogLoadErrorKey = null;
    private ?string $dialogLoadErrorMessage = null;

    public function handle(): int
    {
        $groupId = (int) $this->argument('groupId');
        $batchNo = (int) $this->argument('batchNo');

        Log::info('telegram_v5_command_start', [
            'group_id' => $groupId,
            'batch_no' => $batchNo,
            'started_at' => now()->toDateTimeString(),
            'pid' => getmypid(),
        ]);

        $group = MessageGroup::find($groupId);

        if (!$group) {
            Log::error('telegram_v5_group_not_found', [
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

            return self::FAILURE;
        }

        $user = $group->phone?->user ?? User::find($group->user_id ?? null);

        $this->madeline = $this->madeline($userPhone->session_path);

        if (!$this->madeline) {
            $errorKey = $this->madelineStartErrorKey ?? 'session_invalid';
            $errorMessage = $this->madelineStartErrorMessage ?? 'Madeline start failed';

            Log::warning('telegram_v5_madeline_start_failed', [
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

            Log::info('telegram_v5_dialogs_loaded', [
                'group_id' => $group->id,
                'batch_no' => $batchNo,
                'dialogs_count' => count($dialogIds),
                'error_key' => $this->dialogLoadErrorKey,
            ]);

            if ($this->dialogLoadErrorKey && $this->isSessionErrorKey($this->dialogLoadErrorKey)) {
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
                    $resolved = $this->resolvePeerFromCacheOrMadeline($rawPeer, $dialogIndex);

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
                        $peerModel = $resolved['peer_model'] ?? null;

    if ($peerModel) {
        $peerModel->increment('usage_count');
        $peerModel->update([
            'last_used_at' => now(),
        ]);
    }

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

                    $this->logThrowable('telegram_v5_send_loop_exception', $e, [
                        'group_id' => $group->id,
                        'batch_no' => $batchNo,
                        'peer' => $rawPeer,
                        'error_key' => $errorKey,
                        'stage' => 'send_loop',
                    ]);

                    if ($this->isOperationCancelledError($e->getMessage())) {
                        sleep(1);
                        continue;
                    }

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

    private function resolvePeerFromCacheOrMadeline(string $rawPeer, array $dialogIndex): array
    {
        $normalizedPeer = $this->normalizePeerValue($rawPeer);

        if ($normalizedPeer === '') {
            return $this->failResult('peer_invalid', 'peer bo‘sh');
        }

        $peer = Peer::query()
            ->where('peer', $normalizedPeer)
            ->first();

        if (!$peer) {
            return $this->syncPeerFromMadeline(
                rawPeer: $rawPeer,
                normalizedPeer: $normalizedPeer,
                dialogIndex: $dialogIndex,
                existingPeer: null,
                forceRefresh: true
            );
        }

        $peerId = $this->normalizeValue($peer->peer_id);

        if ($peerId === '') {
            return $this->syncPeerFromMadeline(
                rawPeer: $rawPeer,
                normalizedPeer: $normalizedPeer,
                dialogIndex: $dialogIndex,
                existingPeer: $peer,
                forceRefresh: true
            );
        }

        if ($this->isPeerHardStale($peer)) {
            return $this->syncPeerFromMadeline(
                rawPeer: $rawPeer,
                normalizedPeer: $normalizedPeer,
                dialogIndex: $dialogIndex,
                existingPeer: $peer,
                forceRefresh: true
            );
        }

        if ($this->isPeerSoftStale($peer)) {
            $refreshed = $this->syncPeerFromMadeline(
                rawPeer: $rawPeer,
                normalizedPeer: $normalizedPeer,
                dialogIndex: $dialogIndex,
                existingPeer: $peer,
                forceRefresh: false
            );

            if (($refreshed['ok'] ?? false) === true) {
                return $refreshed;
            }
        }

        if (!isset($dialogIndex['by_id'][$peerId])) {
            $refreshed = $this->syncPeerFromMadeline(
                rawPeer: $rawPeer,
                normalizedPeer: $normalizedPeer,
                dialogIndex: $dialogIndex,
                existingPeer: $peer,
                forceRefresh: false
            );

            if (($refreshed['ok'] ?? false) === true) {
                return $refreshed;
            }

            return $this->failResult('not_member', 'peer dialogda topilmadi');
        }

        return [
            'ok' => true,
            'sendable' => true,
            'peer_type' => $peer->peer_type,
            'resolved_peer' => $peerId,
            'peer_id' => $peer->peer_id,
            'peer_model' => $peer,
            'error_key' => null,
            'reason' => 'cached peer matched',
            'session_error' => false,
        ];
    }

    private function syncPeerFromMadeline(
        string $rawPeer,
        string $normalizedPeer,
        array $dialogIndex,
        ?Peer $existingPeer = null,
        bool $forceRefresh = false
    ): array {
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $info = $this->madeline->getInfo($rawPeer);

                if (!is_array($info)) {
                    $info = [];
                }

                $peerId = $this->normalizeValue($info['bot_api_id'] ?? $info['id'] ?? '');
                $peerType = $info['type'] ?? $info['_'] ?? null;
                $title = $info['title'] ?? $info['name'] ?? $info['first_name'] ?? null;
                $username = $info['username'] ?? null;

                if ($peerId === '') {
                    return $this->failResult('peer_not_found', 'getInfo peer_id bermadi');
                }

                $record = Peer::query()->updateOrCreate(
                    ['peer' => $normalizedPeer],
                    [
                        'peer_id' => $peerId,
                        'peer_type' => $peerType,
                        'title' => $title,
                        'username' => $username,
                        'last_resolved_at' => now(),
                    ]
                );

                if (!isset($dialogIndex['by_id'][$peerId])) {
                    Log::warning('telegram_v5_peer_not_in_dialogs', [
                        'search_peer' => $rawPeer,
                        'peer' => $normalizedPeer,
                        'peer_id' => $peerId,
                        'type' => $peerType,
                    ]);

                    return $this->failResult('not_member', 'peer dialogda topilmadi');
                }

                return [
                    'ok' => true,
                    'sendable' => true,
                    'peer_type' => $peerType,
                    'resolved_peer' => $peerId,
                    'peer_id' => $peerId,
                    'peer_model' => $record,
                    'error_key' => null,
                    'reason' => 'refreshed from madeline',
                    'session_error' => false,
                ];
            } catch (Throwable $e) {
                $message = $this->cleanError($e->getMessage());
                $errorKey = TelegramErrorService::mapErrorToKey($message);

                $this->logThrowable('telegram_v5_peer_getinfo_failed', $e, [
                    'search_peer' => $rawPeer,
                    'peer' => $normalizedPeer,
                    'attempt' => $attempt,
                    'error_key' => $errorKey,
                    'force_refresh' => $forceRefresh,
                ]);

                if ($this->isOperationCancelledError($message) && $attempt < 2) {
                    sleep(1);
                    continue;
                }

                if ($existingPeer && $this->normalizeValue($existingPeer->peer_id) !== '') {
                    $peerId = $this->normalizeValue($existingPeer->peer_id);

                    if (isset($dialogIndex['by_id'][$peerId])) {
                        return [
                            'ok' => true,
                            'sendable' => true,
                            'peer_type' => $existingPeer->peer_type,
                            'resolved_peer' => $peerId,
                            'peer_id' => $peerId,
                            'peer_model' => $existingPeer,
                            'error_key' => null,
                            'reason' => 'fallback cached peer',
                            'session_error' => false,
                        ];
                    }
                }

                if ($this->isOperationCancelledError($message)) {
                    return $this->failResult('operation_canceled', 'getInfo operation canceled');
                }

                return $this->failResult('peer_not_found', 'peer topilmadi');
            }
        }

        return $this->failResult('peer_not_found', 'peer topilmadi');
    }

    private function isPeerSoftStale(Peer $peer): bool
    {
        $baseAt = $peer->last_resolved_at ?? $peer->updated_at ?? $peer->created_at;

        if (!$baseAt) {
            return true;
        }

        return $baseAt->lte(now()->subDays($this->peerSoftRefreshDays));
    }

    private function isPeerHardStale(Peer $peer): bool
    {
        $baseAt = $peer->last_resolved_at ?? $peer->updated_at ?? $peer->created_at;

        if (!$baseAt) {
            return true;
        }

        return $baseAt->lte(now()->subDays($this->peerHardRefreshDays));
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

            $this->madelineStartErrorKey = null;
            $this->madelineStartErrorMessage = null;

            return $madeline;
        } catch (Throwable $e) {
            $this->madelineStartErrorKey = 'madeline_start_failed';
            $this->madelineStartErrorMessage = $e->getMessage();

            $this->logThrowable('telegram_v5_madeline_start_exception', $e, [
                'session_path' => $sessionPath,
            ]);

            return null;
        }
    }

    private function loadDialogIdsUnion(
        int $attempts = 4,
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

                    $this->dialogLoadErrorKey = $errorKey;
                    $this->dialogLoadErrorMessage = $message;

                    // $this->logThrowable('telegram_v5_dialog_ids_load_failed', $e, [
                    //     'attempt' => $i,
                    //     'error_key' => $errorKey,
                    //     'error_message' => $message,
                    // ]);

                    sleep(1);
                    continue;
                }

                $this->dialogLoadErrorKey = $errorKey;
                $this->dialogLoadErrorMessage = $message;

                // $this->logThrowable('telegram_v5_dialog_ids_load_failed', $e, [
                //     'attempt' => $i,
                //     'error_key' => $errorKey,
                //     'error_message' => $message,
                // ]);

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

            Log::info('telegram_v5_dialog_ids_attempt', [
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

        return $result;
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

    private function sendMessageToPeer(string|int $peer, string $messageText): array
    {
        for ($attempt = 1; $attempt <= 2; $attempt++) {
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

                $this->logThrowable('telegram_v5_send_message_failed', $e, [
                    'peer' => $peer,
                    'attempt' => $attempt,
                    'error_key' => $key,
                ]);

                if ($this->isOperationCancelledError($err) && $attempt < 2) {
                    sleep(1);
                    continue;
                }

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

                        $this->logThrowable('telegram_v5_send_message_html_retry_failed', $e2, [
                            'peer' => $peer,
                            'attempt' => $attempt,
                            'error_key' => $key2,
                        ]);

                        if ($this->isOperationCancelledError($err2)) {
                            sleep(1);
                            return [
                                'ok' => false,
                                'status' => 'failed',
                                'telegram_message_id' => null,
                                'error_key' => 'operation_canceled',
                            ];
                        }

                        return [
                            'ok' => false,
                            'status' => 'failed',
                            'telegram_message_id' => null,
                            'error_key' => $key2,
                        ];
                    }
                }

                if ($this->isOperationCancelledError($err)) {
                    sleep(1);
                    return [
                        'ok' => false,
                        'status' => 'failed',
                        'telegram_message_id' => null,
                        'error_key' => 'operation_canceled',
                    ];
                }

                return [
                    'ok' => false,
                    'status' => 'failed',
                    'telegram_message_id' => null,
                    'error_key' => $key,
                ];
            }
        }

        return [
            'ok' => false,
            'status' => 'failed',
            'telegram_message_id' => null,
            'error_key' => 'unknown_error',
        ];
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

    private function normalizePeerValue(mixed $value): string
    {
        $value = $this->normalizeValue($value);

        return rtrim($value, '/');
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

    private function isOperationCancelledError(string $message): bool
    {
        $message = strtolower($message);

        return str_contains($message, 'the operation was cancelled')
            || str_contains($message, 'operation was cancelled')
            || str_contains($message, 'operation cancelled');
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

    private function markMessageFailed($message, string $errorKey, string $stage): void
    {
        $message->increment('attempts');
        $message->update([
            'status' => 'failed',
            'error_key' => $errorKey,
        ]);

        Log::warning('telegram_v5_message_failed', [
            'message_id' => $message->id,
            'peer' => $message->peer,
            'error_key' => $errorKey,
            'stage' => $stage,
        ]);
    }

    private function failPeerPendingMessages(MessageGroup $group, string $peer, string $errorKey): void
    {
        $group->messages()
            ->where('peer', $peer)
            ->where('status', 'pending')
            ->update([
                'status' => 'failed',
                'error_key' => $errorKey,
            ]);
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
            $this->logThrowable('telegram_v5_advance_group_failed', $e, [
                'group_id' => $group->id,
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
        ], true);
    }

    private function logThrowable(string $event, Throwable $e, array $context = []): void
    {
        Log::error($event, array_merge($context, [
            'exception_class' => get_class($e),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'previous' => $e->getPrevious() ? [
                'class' => get_class($e->getPrevious()),
                'message' => $e->getPrevious()->getMessage(),
                'code' => $e->getPrevious()->getCode(),
            ] : null,
        ]));
    }
}