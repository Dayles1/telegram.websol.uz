<?php

namespace Tests\Unit;

use App\Telegram\Errors\ErrorKind;
use App\Telegram\Errors\ErrorMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ErrorMapTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string, 2: ErrorKind, 3: ?int}> */
    public static function errors(): array
    {
        return [
            'flood wait' => ['FLOOD_WAIT_120', 'flood_wait', ErrorKind::Wait, 120],
            'premium flood' => ['FLOOD_PREMIUM_WAIT_5', 'flood_wait', ErrorKind::Wait, 5],
            'slow mode' => ['SLOWMODE_WAIT_30', 'slowmode_wait', ErrorKind::Wait, 30],
            'peer flood' => ['PEER_FLOOD', 'peer_flood', ErrorKind::PeerFlood, null],
            'revoked' => ['AUTH_KEY_UNREGISTERED', 'session_revoked', ErrorKind::DeadSession, null],
            'banned account' => ['USER_DEACTIVATED_BAN', 'account_banned', ErrorKind::DeadSession, null],
            'deleted receiver is not our ban' => ['INPUT_USER_DEACTIVATED', 'peer_deactivated', ErrorKind::Peer, null],
            'unknown username' => ['USERNAME_NOT_OCCUPIED', 'peer_not_found', ErrorKind::Peer, null],
            'madeline peer db' => ['This peer is not present in the internal peer database', 'peer_not_found', ErrorKind::Peer, null],
            'privacy' => ['USER_PRIVACY_RESTRICTED', 'privacy_restricted', ErrorKind::Peer, null],
            'wrong code' => ['PHONE_CODE_INVALID', 'code_invalid', ErrorKind::CodeInvalid, null],
            'wrong password' => ['PASSWORD_HASH_INVALID', 'password_invalid', ErrorKind::PasswordInvalid, null],
            'frozen' => ['FROZEN_METHOD_INVALID', 'account_frozen', ErrorKind::Frozen, null],
            'telegram down' => ['RPC_CALL_FAIL', 'telegram_internal', ErrorKind::Transient, null],
            'network' => ['Connection reset by peer', 'network_error', ErrorKind::Transient, null],
            'anything else' => ['SOMETHING_NEW', 'unknown_error', ErrorKind::Unknown, null],
        ];
    }

    #[DataProvider('errors')]
    public function test_it_classifies(string $message, string $key, ErrorKind $kind, ?int $wait): void
    {
        $error = ErrorMap::classify(new RuntimeException($message));

        $this->assertSame($key, $error->key);
        $this->assertSame($kind, $error->kind);
        $this->assertSame($wait, $error->waitSeconds);
    }

    public function test_it_looks_inside_wrapped_exceptions(): void
    {
        $wrapped = new RuntimeException('IPC call failed', 0, new RuntimeException('PEER_FLOOD'));

        $this->assertSame(ErrorKind::PeerFlood, ErrorMap::classify($wrapped)->kind);
    }

    public function test_the_stored_key_keeps_the_wait_like_the_old_rows(): void
    {
        $this->assertSame('flood_wait_120', ErrorMap::classify(new RuntimeException('FLOOD_WAIT_120'))->storedKey());
        $this->assertSame('peer_flood', ErrorMap::classify(new RuntimeException('PEER_FLOOD'))->storedKey());
    }
}
