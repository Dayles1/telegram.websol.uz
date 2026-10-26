<?php

declare(strict_types=1);

namespace App\Jobs\Accounts;

use App\Enums\AccountStatus;
use App\Enums\EventKind;
use App\Monitoring\EventRecorder;
use App\Telegram\Contracts\TelegramGateway;
use App\Telegram\Phone;
use App\Telegram\ProcessLock;
use App\Telegram\SessionStore;
use Throwable;

final class LogoutAccount extends AccountJob
{
    public function handle(TelegramGateway $telegram, SessionStore $sessions, EventRecorder $events): void
    {
        $account = $this->account();
        if ($account === null || $account->status !== AccountStatus::LoggingOut) {
            return;
        }

        // Let a send worker on this session finish its current message first.
        $lock = ProcessLock::for("account-{$account->id}");
        for ($i = 0; $i < 30 && ! $lock->acquire(); $i++) {
            sleep(2);
        }

        try {
            $telegram->logout((string) $account->session_path);
        } catch (Throwable $e) {
            // Logged out on our side anyway; Telegram will drop the session by itself.
            $events->warning(EventKind::Session, 'Logout on Telegram failed, session removed locally', [
                'phone' => Phone::mask($account->phone),
                'raw' => $e->getMessage(),
            ], $account->id);
        } finally {
            $sessions->delete($account->session_path);
            $account->update(['status' => AccountStatus::LoggedOut, 'session_path' => null]);
            $lock->release();
        }

        $events->info(EventKind::Session, 'Account logged out', ['phone' => Phone::mask($account->phone)], $account->id);
    }
}
