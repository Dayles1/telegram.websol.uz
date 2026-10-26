<?php

declare(strict_types=1);

namespace App\Jobs\Accounts;

use App\Enums\AccountStatus;
use App\Telegram\Contracts\TelegramGateway;
use App\Telegram\SessionStore;
use RuntimeException;
use Throwable;

final class RequestLoginCode extends AccountJob
{
    public function handle(TelegramGateway $telegram, SessionStore $sessions): void
    {
        $account = $this->account();
        if ($account === null || $account->status !== AccountStatus::RequestingCode) {
            return;
        }

        // A second click on "login" while the previous process still saves the session: wait for it first.
        if (! $sessions->holdUntilExit($account->session_path)) {
            $this->fail($account, new RuntimeException('SESSION_BUSY'), 'request_code');

            return;
        }

        // A fresh login always starts from an empty session.
        $sessions->delete($account->session_path);

        try {
            $telegram->requestCode($account->session_path, $account->phone);
            $account->update(['status' => AccountStatus::CodeSent]);
        } catch (Throwable $e) {
            $this->fail($account, $e, 'request_code');
        }
    }
}
