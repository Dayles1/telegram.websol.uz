<?php

namespace App\Services\Telegram;

use App\Models\UserPhone;
use danog\MadelineProto\API;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramPeerMessagingService
{
    protected ?API $madeline = null;

    public function initMadeline(UserPhone $userPhone): bool
    {
        $path = $userPhone->session_path;

        if (!$path || !File::exists($path)) {
            $this->madeline = null;
            return false;
        }

        try {
            $this->madeline = new API($path);
            $this->madeline->start();

            return true;
        } catch (Throwable $e) {
            $this->madeline = null;
            return false;
        }
    }

    public function cleanupSession(UserPhone $userPhone): void
    {
        $this->forceLogout();
    }

    public function forceLogout(): void
    {
        try {
            if ($this->madeline && method_exists($this->madeline, 'logout')) {
                $this->madeline->logout();
            }
        } catch (Throwable $e) {
            // intentionally silent
        }

        $this->madeline = null;
    }

    public function parsePeer(string $rawPeer): array
    {
        $rawPeer = trim($rawPeer);

        if ($rawPeer === '') {
            return [
                'ok' => false,
                'type' => 'invalid',
                'raw' => $rawPeer,
                'reason' => 'peer bo‘sh',
            ];
        }

        if (preg_match('~^(?:https?://)?(?:www\.)?(?:t\.me|telegram\.me)/\+([A-Za-z0-9_-]+)$~i', $rawPeer, $m)) {
            return [
                'ok' => true,
                'type' => 'invite_link',
                'raw' => $rawPeer,
                'hash' => $m[1],
            ];
        }

        if (preg_match('~^(?:https?://)?(?:www\.)?(?:t\.me|telegram\.me)/c/(\d+)(?:/\d+)?$~i', $rawPeer, $m)) {
            return [
                'ok' => true,
                'type' => 'internal_link',
                'raw' => $rawPeer,
                'internal_id' => $m[1],
            ];
        }

        if (preg_match('~^(?:https?://)?(?:www\.)?(?:t\.me|telegram\.me)/@?([A-Za-z0-9_]{5,32})$~i', $rawPeer, $m)) {
            return [
                'ok' => true,
                'type' => 'username',
                'raw' => $rawPeer,
                'username' => $m[1],
            ];
        }

        if (preg_match('~^@([A-Za-z0-9_]{5,32})$~', $rawPeer, $m)) {
            return [
                'ok' => true,
                'type' => 'username',
                'raw' => $rawPeer,
                'username' => $m[1],
            ];
        }

        if (preg_match('~^-?\d+$~', $rawPeer)) {
            return [
                'ok' => true,
                'type' => 'id',
                'raw' => $rawPeer,
                'id' => (int) $rawPeer,
            ];
        }

        if (preg_match('~^\+?\d{7,15}$~', $rawPeer)) {
            return [
                'ok' => true,
                'type' => 'phone',
                'raw' => $rawPeer,
                'phone' => $this->normalizePhone($rawPeer),
            ];
        }

        return [
            'ok' => false,
            'type' => 'invalid',
            'raw' => $rawPeer,
            'reason' => 'peer formati noma’lum',
        ];
    }

    public function inspectPeer(string $rawPeer): array
    {
        $peer = $this->parsePeer($rawPeer);

        if (!$peer['ok']) {
            return $this->failResult('peer_invalid', $peer['reason'] ?? 'peer invalid', $rawPeer);
        }

        if (!$this->madeline) {
            return $this->failResult('madeline_not_initialized', 'session ishlamayapti', $rawPeer);
        }

        try {
            return match ($peer['type']) {
                'username' => $this->inspectUsername($peer),
                'id' => $this->inspectId($peer),
                'invite_link' => $this->inspectInviteLink($peer),
                'internal_link' => $this->inspectInternalLink($peer),
                'phone' => $this->inspectPhone($peer),
                default => $this->failResult('peer_invalid', 'peer turi noma’lum', $rawPeer),
            };
        } catch (Throwable $e) {
            $err = $this->cleanError($e->getMessage());
            $key = $this->mapErrorToKey($err);

            if ($key === 'unknown_error') {
                $this->logUnknownError('inspect_peer', $e, [
                    'peer' => $rawPeer,
                    'peer_type' => $peer['type'] ?? null,
                    'clean_error' => $err,
                ]);
            }

            return $this->failResult($key, $this->humanReason($key), $rawPeer, $err);
        }
    }

    public function sendMessageToPeer(string $rawPeer, string $message, ?array $precheck = null): array
    {
        $inspect = $precheck ?? $this->inspectPeer($rawPeer);

        if (!($inspect['ok'] ?? false) || !($inspect['sendable'] ?? false)) {
            return [
                'ok' => false,
                'status' => 'failed',
                'telegram_message_id' => null,
                'error_key' => $inspect['error_key'] ?? 'peer_invalid',
                'result_label' => 'yozolmaymiz',
                'reason' => $inspect['reason'] ?? null,
                'stage' => $inspect['stage'] ?? 'precheck',
                'session_error' => $inspect['session_error'] ?? false,
                'resolved_peer' => null,
            ];
        }

        try {
            $response = $this->madeline->messages->sendMessage([
                'peer' => $inspect['resolved_peer'],
                'message' => $message,
                'parse_mode' => 'HTML',
            ]);

            [$status, $telegramMessageId] = $this->extractSendResult($response);

            return [
                'ok' => true,
                'status' => $status,
                'telegram_message_id' => $telegramMessageId,
                'error_key' => null,
                'result_label' => 'yozolamiz',
                'reason' => 'xabar yuborildi',
                'stage' => 'sendMessage',
                'session_error' => false,
                'resolved_peer' => $inspect['resolved_peer'],
            ];
        } catch (Throwable $e) {
            $err = $this->cleanError($e->getMessage());
            $key = $this->mapErrorToKey($err);

            if ($key === 'unknown_error') {
                $this->logUnknownError('after_send_message', $e, [
                    'peer' => $rawPeer,
                ]);
            }

            if ($this->isHtmlParseError($err)) {
                try {
                    $response = $this->madeline->messages->sendMessage([
                        'peer' => $inspect['resolved_peer'],
                        'message' => $message,
                    ]);

                    [$status, $telegramMessageId] = $this->extractSendResult($response);

                    return [
                        'ok' => true,
                        'status' => $status,
                        'telegram_message_id' => $telegramMessageId,
                        'error_key' => null,
                        'result_label' => 'yozolamiz',
                        'reason' => 'xabar yuborildi (plain text fallback)',
                        'stage' => 'sendMessageRetryPlainText',
                        'session_error' => false,
                        'resolved_peer' => $inspect['resolved_peer'],
                    ];
                } catch (Throwable $e2) {
                    $err2 = $this->cleanError($e2->getMessage());
                    $key2 = $this->mapErrorToKey($err2);

                    if ($key2 === 'unknown_error') {
                        Log::error('telegram_unknown_error', [
                            'stage' => 'send_message_retry_plain_text',
                            'peer' => $rawPeer,
                        ]);
                    }

                    return [
                        'ok' => false,
                        'status' => 'failed',
                        'telegram_message_id' => null,
                        'error_key' => $key2,
                        'result_label' => 'yozolmaymiz',
                        'reason' => $this->humanReason($key2),
                        'stage' => 'sendMessageRetryPlainText',
                        'session_error' => $this->isSessionErrorKey($key2),
                        'exception' => $err2,
                        'resolved_peer' => $inspect['resolved_peer'] ?? null,
                    ];
                }
            }

            return [
                'ok' => false,
                'status' => 'failed',
                'telegram_message_id' => null,
                'error_key' => $key,
                'result_label' => 'yozolmaymiz',
                'reason' => $this->humanReason($key),
                'stage' => 'sendMessage',
                'session_error' => $this->isSessionErrorKey($key),
                'exception' => $err,
                'resolved_peer' => $inspect['resolved_peer'] ?? null,
            ];
        }
    }

    private function inspectUsername(array $peer): array
    {
        $info = $this->madeline->getInfo($peer['username']);

        $type = $info['type'] ?? $info['_'] ?? null;
        $peerId = $info['bot_api_id']
            ?? $info['user_id']
            ?? $info['chat_id']
            ?? $info['channel_id']
            ?? $info['peer_id']
            ?? null;

        if ($type === 'user') {
            return $this->inspectUserPeer($peer, $info, $peer['username']);
        }

        if (!in_array($type, ['chat', 'supergroup', 'channel'], true)) {
            return $this->failResult('peer_invalid', 'peer turi noma’lum', $peer['raw']);
        }

        return $this->inspectChatLikePeer($peer['username'], $peerId ?: $peer['username'], 'username', $peer['raw']);
    }

    private function inspectId(array $peer): array
    {
        $info = $this->madeline->getInfo($peer['id']);

        $type = $info['type'] ?? $info['_'] ?? null;
        $peerId = $info['bot_api_id']
            ?? $info['user_id']
            ?? $info['chat_id']
            ?? $info['channel_id']
            ?? $info['peer_id']
            ?? $peer['id'];

        if ($type === 'user') {
            return $this->inspectUserPeer($peer, $info, $peerId);
        }

        if (!in_array($type, ['chat', 'supergroup', 'channel'], true)) {
            return $this->failResult('peer_invalid', 'ID peer topilmadi', $peer['raw']);
        }

        return $this->inspectChatLikePeer($peerId, $peerId, 'id', $peer['raw']);
    }

    private function inspectUserPeer(array $peer, array $info, string|int $resolvedPeer): array
    {
        if (($info['deleted'] ?? false) === true) {
            return $this->failResult('peer_not_found', 'foydalanuvchi o‘chirilgan', $peer['raw']);
        }

        if (($info['restricted'] ?? false) === true) {
            return $this->failResult('restricted', 'foydalanuvchi restricted', $peer['raw']);
        }

        return [
            'ok' => true,
            'sendable' => true,
            'peer_type' => 'user',
            'resolved_peer' => $resolvedPeer,
            'error_key' => null,
            'reason' => 'private user ok',
            'session_error' => false,
        ];
    }

    private function inspectInternalLink(array $peer): array
    {
        $peerId = '-100' . $peer['internal_id'];

        return $this->inspectChatLikePeer($peerId, $peerId, 'internal_link', $peer['raw']);
    }

    private function inspectInviteLink(array $peer): array
    {
        try {
            $invite = $this->madeline->messages->checkChatInvite([
                'hash' => $peer['hash'],
            ]);

            $type = $invite['_'] ?? null;

            if ($type === 'chatInviteAlready') {
                $chat = $invite['chat'] ?? null;
                $chatId = $chat['id'] ?? null;

                if (!$chatId) {
                    return $this->failResult('chat_info_missing', 'invite chat topilmadi', $peer['raw']);
                }

                return $this->inspectChatLikePeer($chatId, $chatId, 'invite_link', $peer['raw']);
            }

            if ($type === 'chatInvite') {
                try {
                    $joined = $this->madeline->messages->importChatInvite([
                        'hash' => $peer['hash'],
                    ]);

                    $chat = $joined['chats'][0] ?? null;
                    $chatId = $chat['id'] ?? null;

                    if (!$chatId) {
                        return $this->failResult('chat_info_missing', 'invite qabul qilindi, lekin chat topilmadi', $peer['raw']);
                    }

                    return $this->inspectChatLikePeer($chatId, $chatId, 'invite_link', $peer['raw']);
                } catch (Throwable $e) {
                    $err = $this->cleanError($e->getMessage());
                    $key = $this->mapErrorToKey($err);

                    if ($key === 'unknown_error') {
                        Log::error('telegram_unknown_error', [
                            'stage' => 'invite_join',
                            'peer' => $peer['raw'],
                        ]);
                    }

                    return $this->failResult($key, $this->humanReason($key), $peer['raw']);
                }
            }

            return $this->failResult('invite_invalid', 'invite link yaroqsiz', $peer['raw']);
        } catch (Throwable $e) {
            $err = $this->cleanError($e->getMessage());
            $key = $this->mapErrorToKey($err);

            if ($key === 'unknown_error') {
                Log::error('telegram_unknown_error', [
                    'stage' => 'invite_check',
                    'peer' => $peer['raw'],
                ]);
            }

            return $this->failResult($key, $this->humanReason($key), $peer['raw']);
        }
    }

    private function inspectPhone(array $peer): array
    {
        return $this->failResult(
            'phone_not_supported_directly',
            'telefon raqam peer emas; contact orqali resolve qilish kerak',
            $peer['raw']
        );
    }

    private function inspectChatLikePeer(string|int $resolvedPeer, string|int $peerId, string $peerType, string $rawPeer): array
    {
        $participant = $this->safeGetParticipant($peerId);

        if (!$participant) {
            return $this->failResult('not_member', 'member emas', $rawPeer);
        }

        $participantBlock = $this->extractParticipantBlock($participant);
        $participantType = $participantBlock['_'] ?? $participant['_'] ?? null;

        if (in_array($participantType, ['channelParticipantBanned', 'channelParticipantLeft'], true)) {
            return $this->failResult('not_member', 'member emas yoki chiqib ketgan', $rawPeer);
        }

        if ($this->isParticipantBannedToSend($participant)) {
            return $this->failResult('chat_write_forbidden', 'yozish taqiqlangan', $rawPeer);
        }

        $full = $this->madeline->getFullInfo($peerId);
        $chat = $this->extractChatBlock($full);

        if (!$chat) {
            return $this->failResult('chat_info_missing', 'chat ma’lumoti topilmadi', $rawPeer);
        }

        $isAdmin = $this->isAdminOrCreator($participant);

        $defaultBannedRights = $chat['default_banned_rights'] ?? [];
        $canSendMessages = $chat['can_send_messages'] ?? null;
        $slowmodeSeconds = (int) ($chat['slowmode_seconds'] ?? 0);
        $isBroadcast = (bool) ($chat['broadcast'] ?? false);

        if ($isBroadcast && !$isAdmin) {
            return $this->failResult('chat_write_forbidden', 'broadcast kanalga faqat admin yozishi mumkin', $rawPeer);
        }

        if (!$isAdmin && $this->isWriteForbiddenByDefaults($canSendMessages, $defaultBannedRights)) {
            return $this->failResult('chat_write_forbidden', 'guruh/channel yozishni taqiqlagan', $rawPeer);
        }

        if ($slowmodeSeconds > 0) {
            return [
                'ok' => true,
                'sendable' => true,
                'peer_type' => $peerType,
                'resolved_peer' => $resolvedPeer,
                'error_key' => null,
                'reason' => "slowmode bor ({$slowmodeSeconds}s)",
                'slowmode_seconds' => $slowmodeSeconds,
                'session_error' => false,
            ];
        }

        return [
            'ok' => true,
            'sendable' => true,
            'peer_type' => $peerType,
            'resolved_peer' => $resolvedPeer,
            'error_key' => null,
            'reason' => 'yozish mumkin',
            'session_error' => false,
        ];
    }

    private function safeGetParticipant(string|int $channel): mixed
    {
        try {
            return $this->madeline->channels->getParticipant([
                'channel' => $channel,
                'participant' => 'me',
            ]);
        } catch (Throwable $e) {
            return null;
        }
    }

    private function extractParticipantBlock(mixed $participant): array
    {
        if (is_array($participant) && isset($participant['participant']) && is_array($participant['participant'])) {
            return $participant['participant'];
        }

        return is_array($participant) ? $participant : [];
    }

    private function isParticipantBannedToSend(mixed $participant): bool
    {
        $block = $this->extractParticipantBlock($participant);
        $type = $block['_'] ?? null;

        if ($type === 'channelParticipantBanned') {
            return true;
        }

        $bannedRights = $block['banned_rights'] ?? null;

        if (is_array($bannedRights) && (($bannedRights['send_messages'] ?? null) === true)) {
            return true;
        }

        return false;
    }

    private function isAdminOrCreator(mixed $participant): bool
    {
        $block = $this->extractParticipantBlock($participant);
        $type = $block['_'] ?? null;

        if (in_array($type, ['channelParticipantCreator', 'channelParticipantAdmin'], true)) {
            return true;
        }

        if (!empty($block['admin_rights'])) {
            return true;
        }

        return false;
    }

    private function isWriteForbiddenByDefaults(mixed $canSendMessages, array $defaultBannedRights): bool
    {
        $sendMessagesBanned = $defaultBannedRights['send_messages'] ?? null;

        if ($canSendMessages === false) {
            return true;
        }

        if ($sendMessagesBanned === true) {
            return true;
        }

        return false;
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

    private function extractChatBlock(array $fullInfo): ?array
    {
        foreach (['Chat', 'chat', 'full_chat'] as $key) {
            if (isset($fullInfo[$key]) && is_array($fullInfo[$key])) {
                return $fullInfo[$key];
            }
        }

        return null;
    }

    private function failResult(string $errorKey, string $reason, ?string $peer = null, ?string $exception = null): array
    {
        return [
            'ok' => false,
            'sendable' => false,
            'resolved_peer' => $peer,
            'error_key' => $errorKey,
            'result_label' => 'yozolmaymiz',
            'reason' => $reason,
            'exception' => $exception,
            'session_error' => $this->isSessionErrorKey($errorKey),
        ];
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\s+/', '', $phone);
    }

    public function mapErrorToKey(string $err): string
    {
        $e = strtolower($err);

        if (preg_match('/flood[_ ]?wait[_ ]?(\d+)/i', $err, $m)) {
            return !empty($m[1]) ? "flood_wait_{$m[1]}" : 'flood_wait';
        }

        if (preg_match('/slowmode[_ ]?wait[_ ]?(\d+)/i', $err, $m)) {
            return !empty($m[1]) ? "slowmode_wait_{$m[1]}" : 'slowmode_wait';
        }

        if (str_contains($e, 'peer flood')) {
            return 'peer_flood';
        }

        if (
            str_contains($e, 'not member') ||
            str_contains($e, 'not_member') ||
            str_contains($e, 'member is not a participant') ||
            str_contains($e, 'user not participant') ||
            str_contains($e, 'participant not found') ||
            str_contains($e, 'user is not a participant')
        ) {
            return 'not_member';
        }

        if (
            str_contains($e, 'chat_write_forbidden') ||
            str_contains($e, 'chat write forbidden') ||
            str_contains($e, 'chat admin required') ||
            str_contains($e, 'CHAT_SEND_PLAIN_FORBIDDEN') ||
            str_contains($e, 'chat_send_plain_forbidden') ||
            str_contains($e, 'send plain') ||
            str_contains($e, 'write forbidden')
        ) {
            return 'chat_write_forbidden';
        }

        if (str_contains($e, 'user_banned_in_channel')) {
            return 'user_banned_in_channel';
        }

        if (str_contains($e, 'user is blocked') || str_contains($e, 'user_is_blocked') || str_contains($e, 'bot was blocked')) {
            return 'user_is_blocked';
        }

        if (str_contains($e, 'topic closed')) {
            return 'topic_closed';
        }

        if (str_contains($e, 'topic deleted')) {
            return 'topic_deleted';
        }

        if (str_contains($e, 'auth_key_unregistered') || str_contains($e, 'session_revoked') || str_contains($e, 'auth_key_invalid')) {
            return 'auth_key_invalid';
        }

        if (preg_match('/timeout|timed out|connection.*reset|broken pipe|could not connect/i', $e)) {
            return 'network_error';
        }

        if (
            str_contains($e, 'peer is not present in the internal peer database') ||
            str_contains($e, 'peer not found') ||
            str_contains($e, 'chat not found') ||
            str_contains($e, 'group not found')
        ) {
            return 'peer_not_found';
        }

        if (str_contains($e, 'madeline api is null') || str_contains($e, 'getinfo() on null')) {
            return 'madeline_not_initialized';
        }

        if (str_contains($e, 'invite hash invalid') || str_contains($e, 'invite link')) {
            return 'invite_invalid';
        }

        return 'unknown_error';
    }

    public function isSessionErrorKey(string $errorKey): bool
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

    private function isHtmlParseError(string $err): bool
    {
        $e = strtolower($err);

        return str_contains($e, 'can\'t parse entities')
            || str_contains($e, 'cannot parse entities')
            || str_contains($e, 'parse entities')
            || str_contains($e, 'entity')
            || str_contains($e, 'tag');
    }

    private function humanReason(string $errorKey): string
    {
        return match ($errorKey) {
            'peer_invalid' => 'peer noto‘g‘ri',
            'chat_write_forbidden' => 'chat/channel yozishni taqiqlagan',
            'not_member' => 'guruh a’zosi emas',
            'chat_info_missing' => 'chat ma’lumoti topilmadi',
            'slowmode_wait' => 'slowmode bor',
            'peer_flood' => 'telegram limit',
            'flood_wait' => 'flood wait',
            'user_banned_in_channel' => 'kanalda yozish taqiqlangan',
            'user_is_blocked' => 'foydalanuvchi bloklagan',
            'topic_closed' => 'topic yopilgan',
            'topic_deleted' => 'topic o‘chirilgan',
            'peer_not_found' => 'peer topilmadi',
            'auth_key_invalid' => 'session buzilgan',
            'network_error' => 'tarmoq xatosi',
            'madeline_not_initialized' => 'madeline yoqilmagan',
            'invite_invalid' => 'invite link yaroqsiz',
            'phone_not_supported_directly' => 'telefon raqamni direkt peer qilib bo‘lmaydi',
            default => $errorKey,
        };
    }


    private function cleanError(string $err): string
    {
        return trim(preg_replace('/\s+/', ' ', $err));
    }
    private function logUnknownError(string $stage, Throwable $e, array $context = []): void
    {
        Log::error('telegram_unknown_error', [
            'stage' => $stage,
            'message' => $e->getMessage(),
            'context' => $context,
        ]);
    }
}
