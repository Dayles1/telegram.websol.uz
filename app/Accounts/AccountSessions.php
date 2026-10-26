<?php

declare(strict_types=1);

namespace App\Accounts;

use App\Enums\AccountStatus;
use App\Enums\EventKind;
use App\Enums\MessageStatus;
use App\Exceptions\ActionRefused;
use App\Jobs\Accounts\CheckAccountSession;
use App\Jobs\Accounts\LogoutAccount;
use App\Models\MessageTask;
use App\Models\SendLoop;
use App\Models\TelegramMessage;
use App\Models\UserPhone;
use App\Monitoring\EventRecorder;
use App\Telegram\Errors\TelegramError;
use App\Telegram\Phone;
use App\Telegram\SessionStore;
use Illuminate\Support\Facades\DB;

/**
 * Life of an account after login: health checks, logout, removal, and what
 * happens when Telegram kills a session.
 */
final class AccountSessions
{
    public function __construct(
        private readonly SessionStore $sessions,
        private readonly EventRecorder $events,
    ) {}

    public function check(UserPhone $account): void
    {
        if (! in_array($account->status, [AccountStatus::Authorized, AccountStatus::Revoked], true)) {
            throw new ActionRefused('not_authorized');
        }

        CheckAccountSession::dispatch($account->id);
    }

    public function logout(UserPhone $account): void
    {
        DB::transaction(function () use ($account): void {
            $account = UserPhone::query()->lockForUpdate()->findOrFail($account->id);
            if ($account->status->isInFlight()) {
                throw new ActionRefused('login_in_progress');
            }

            $account->update(['status' => AccountStatus::LoggingOut, 'login_started_at' => now()]);
            $this->cancelPendingMessages($account, 'session_logged_out');
            LogoutAccount::dispatch($account->id)->afterCommit();
        });
    }

    /**
     * Removes an account from the panel. History stays: a phone with sends, single
     * messages, repeating sends or chat logs behind it is only logged out and
     * hidden, because deleting it would cascade.
     */
    public function remove(UserPhone $account): void
    {
        if ($account->status === AccountStatus::Authorized || $account->status->isInFlight()) {
            throw new ActionRefused('logout_first');
        }

        $hasHistory = $account->messageGroups()->exists()
            || MessageTask::query()->where('sender_phone_id', $account->id)->exists()
            || $account->chatLogGroups()->exists()
            || SendLoop::query()->where('user_phone_id', $account->id)->exists();
        if ($hasHistory) {
            throw new ActionRefused('has_history');
        }

        $this->sessions->delete($account->session_path);
        $account->delete();
    }

    /**
     * Telegram says the session is dead (revoked, key unregistered, account banned).
     * Never throws: called from inside workers' error handling.
     */
    public function retire(int $accountId, TelegramError $error): void
    {
        $account = UserPhone::query()->find($accountId);
        if ($account === null) {
            return;
        }

        $this->sessions->delete($account->session_path);
        $account->update([
            'status' => AccountStatus::Revoked,
            'session_path' => null,
            'last_error' => $error->key,
            'last_checked_at' => now(),
        ]);
        $failed = $this->cancelPendingMessages($account, $error->key, MessageStatus::Failed);

        $this->events->error(EventKind::Session, 'Telegram session is dead, the account must log in again', [
            'phone' => Phone::mask($account->phone),
            'raw' => $error->raw,
            'pending_messages_failed' => $failed,
        ], $account->id, errorKey: $error->key);
    }

    /** Pending messages of an account that can no longer send. */
    public function cancelPendingMessages(UserPhone $account, string $reason, MessageStatus $as = MessageStatus::Failed): int
    {
        return TelegramMessage::query()
            ->whereIn('message_group_id', $account->messageGroups()->select('id'))
            ->where('status', MessageStatus::Pending)
            ->update(['status' => $as, 'error_key' => $reason, 'updated_at' => now()]);
    }
}
