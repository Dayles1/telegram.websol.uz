<?php

declare(strict_types=1);

namespace App\Telegram\Errors;

use danog\MadelineProto\RPCError\FloodWaitError;
use danog\MadelineProto\RPCErrorException;
use Throwable;

/**
 * The single place that turns a MadelineProto/Telegram exception into a TelegramError.
 *
 * Exceptions may arrive wrapped (IPC, Amp), so the whole getPrevious() chain is
 * searched, and both the RPC code and the plain message are matched.
 */
final class ErrorMap
{
    /** RPC code (or prefix ending with "_") => [key, kind]. Order matters: first match wins. */
    private const RULES = [
        'FLOOD_WAIT_' => ['flood_wait', ErrorKind::Wait],
        'FLOOD_PREMIUM_WAIT_' => ['flood_wait', ErrorKind::Wait],
        'SLOWMODE_WAIT_' => ['slowmode_wait', ErrorKind::Wait],
        'PEER_FLOOD' => ['peer_flood', ErrorKind::PeerFlood],
        'FROZEN_METHOD_INVALID' => ['account_frozen', ErrorKind::Frozen],
        'FROZEN_' => ['account_frozen', ErrorKind::Frozen],
        'INPUT_USER_DEACTIVATED' => ['peer_deactivated', ErrorKind::Peer], // the receiver, not us: before USER_DEACTIVATED
        'USER_DEACTIVATED_BAN' => ['account_banned', ErrorKind::DeadSession],
        'USER_DEACTIVATED' => ['account_banned', ErrorKind::DeadSession],
        'PHONE_NUMBER_BANNED' => ['account_banned', ErrorKind::DeadSession],
        'AUTH_KEY_UNREGISTERED' => ['session_revoked', ErrorKind::DeadSession],
        'AUTH_KEY_INVALID' => ['session_revoked', ErrorKind::DeadSession],
        'AUTH_KEY_DUPLICATED' => ['session_revoked', ErrorKind::DeadSession],
        'SESSION_REVOKED' => ['session_revoked', ErrorKind::DeadSession],
        'SESSION_EXPIRED' => ['session_revoked', ErrorKind::DeadSession],
        'SESSION_NOT_AUTHORIZED' => ['session_revoked', ErrorKind::DeadSession],
        'SESSION_MISSING' => ['session_missing', ErrorKind::DeadSession],
        'SESSION_BUSY' => ['session_busy', ErrorKind::Transient],
        'PHONE_CODE_INVALID' => ['code_invalid', ErrorKind::CodeInvalid],
        'PHONE_CODE_EMPTY' => ['code_invalid', ErrorKind::CodeInvalid],
        'PHONE_CODE_EXPIRED' => ['code_expired', ErrorKind::Unknown],
        'PASSWORD_HASH_INVALID' => ['password_invalid', ErrorKind::PasswordInvalid],
        'PHONE_NUMBER_INVALID' => ['phone_invalid', ErrorKind::Unknown],
        'PHONE_NUMBER_UNOCCUPIED' => ['phone_not_registered', ErrorKind::Unknown],
        'ACCOUNT_NOT_REGISTERED' => ['phone_not_registered', ErrorKind::Unknown],
        'API_ID_INVALID' => ['api_id_invalid', ErrorKind::Unknown],
        'USERNAME_NOT_OCCUPIED' => ['peer_not_found', ErrorKind::Peer],
        'USERNAME_INVALID' => ['peer_not_found', ErrorKind::Peer],
        'PEER_ID_INVALID' => ['peer_not_found', ErrorKind::Peer],
        'CHANNEL_INVALID' => ['peer_not_found', ErrorKind::Peer],
        'CHAT_ID_INVALID' => ['peer_not_found', ErrorKind::Peer],
        'INVITE_HASH_EXPIRED' => ['peer_not_found', ErrorKind::Peer],
        'This peer is not present in the internal peer database' => ['peer_not_found', ErrorKind::Peer],
        'CHANNEL_PRIVATE' => ['chat_private', ErrorKind::Peer],
        'CHAT_WRITE_FORBIDDEN' => ['chat_write_forbidden', ErrorKind::Peer],
        'CHAT_ADMIN_REQUIRED' => ['chat_write_forbidden', ErrorKind::Peer],
        'CHAT_SEND_PLAIN_FORBIDDEN' => ['chat_write_forbidden', ErrorKind::Peer],
        'CHAT_SEND_' => ['chat_write_forbidden', ErrorKind::Peer], // CHAT_SEND_MEDIA_FORBIDDEN, _PHOTOS_, _DOCS_ ...
        'CHAT_RESTRICTED' => ['chat_write_forbidden', ErrorKind::Peer],
        'CHAT_GUEST_SEND_FORBIDDEN' => ['chat_guest_send_forbidden', ErrorKind::Peer],
        'USER_BANNED_IN_CHANNEL' => ['user_banned', ErrorKind::Peer],
        'USER_IS_BLOCKED' => ['user_blocked', ErrorKind::Peer],
        'YOU_BLOCKED_USER' => ['user_blocked', ErrorKind::Peer],
        'USER_PRIVACY_RESTRICTED' => ['privacy_restricted', ErrorKind::Peer],
        'PRIVACY_PREMIUM_REQUIRED' => ['privacy_restricted', ErrorKind::Peer],
        'USER_IS_BOT' => ['peer_is_bot', ErrorKind::Peer],
        'MESSAGE_TOO_LONG' => ['message_too_long', ErrorKind::Peer],
        'MESSAGE_EMPTY' => ['message_empty', ErrorKind::Peer],
        'MEDIA_CAPTION_TOO_LONG' => ['message_too_long', ErrorKind::Peer],
        'MEDIA_EMPTY' => ['attachment_invalid', ErrorKind::Peer],
        'MEDIA_INVALID' => ['attachment_invalid', ErrorKind::Peer],
        'PHOTO_' => ['attachment_invalid', ErrorKind::Peer], // PHOTO_INVALID_DIMENSIONS, PHOTO_EXT_INVALID ...
        'IMAGE_PROCESS_FAILED' => ['attachment_invalid', ErrorKind::Peer],
        'FILE_PARTS_INVALID' => ['attachment_invalid', ErrorKind::Peer],
        'FILE_PART_' => ['network_error', ErrorKind::Transient], // FILE_PART_X_MISSING: the upload broke
        'ENTITY_BOUNDS_INVALID' => ['message_markup_invalid', ErrorKind::Peer],
        'SCHEDULE_TOO_MUCH' => ['schedule_too_much', ErrorKind::Peer],
        'RPC_CALL_FAIL' => ['telegram_internal', ErrorKind::Transient],
        'RPC_MCGET_FAIL' => ['telegram_internal', ErrorKind::Transient],
        'INTERDC_' => ['telegram_internal', ErrorKind::Transient],
        'TIMEOUT' => ['network_error', ErrorKind::Transient],
    ];

    private const NETWORK_PATTERN = '/timed? ?out|connection (reset|refused|closed)|broken pipe|could not connect|network|eof/i';

    public static function classify(Throwable $e): TelegramError
    {
        $messages = [];
        for ($cursor = $e; $cursor !== null; $cursor = $cursor->getPrevious()) {
            if ($cursor instanceof FloodWaitError) {
                return new TelegramError('flood_wait', ErrorKind::Wait, max(1, $cursor->getWaitTime()), $cursor->rpc);
            }
            $messages[] = $cursor instanceof RPCErrorException ? $cursor->rpc : $cursor->getMessage();
        }

        foreach ($messages as $message) {
            if (($error = self::match($message)) !== null) {
                return $error;
            }
        }

        $first = $messages[0] ?? '';
        if (preg_match(self::NETWORK_PATTERN, $first) === 1) {
            return new TelegramError('network_error', ErrorKind::Transient, raw: $first);
        }

        return new TelegramError('unknown_error', ErrorKind::Unknown, raw: mb_substr($first, 0, 500));
    }

    private static function match(string $message): ?TelegramError
    {
        foreach (self::RULES as $needle => [$key, $kind]) {
            if (! str_contains($message, $needle)) {
                continue;
            }
            $wait = null;
            if ($kind === ErrorKind::Wait && preg_match('/_(\d+)/', substr($message, strpos($message, $needle)), $m) === 1) {
                $wait = (int) $m[1];
            }

            return new TelegramError($key, $kind, $wait, $message);
        }

        return null;
    }
}
