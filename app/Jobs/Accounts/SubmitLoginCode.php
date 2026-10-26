<?php

declare(strict_types=1);

namespace App\Jobs\Accounts;

use App\Enums\AccountStatus;
use App\Enums\EventKind;
use App\Monitoring\EventRecorder;
use App\Telegram\Contracts\TelegramGateway;
use App\Telegram\Phone;
use Throwable;

final class SubmitLoginCode extends AccountJob
{
    public function __construct(int $accountId, public readonly string $code)
    {
        parent::__construct($accountId);
    }

    public function handle(TelegramGateway $telegram, EventRecorder $events): void
    {
        $account = $this->account();
        if ($account === null || $account->status !== AccountStatus::Verifying) {
            return;
        }

        try {
            $result = $telegram->submitCode($account->session_path, $this->code);
        } catch (Throwable $e) {
            $this->fail($account, $e, 'code');

            return;
        }

        if ($result['password_required']) {
            $account->update(['status' => AccountStatus::NeedPassword, 'password_hint' => $result['hint']]);

            return;
        }

        $account->update($result['profile']->toColumns() + [
            'status' => AccountStatus::Authorized,
            'authorized_at' => now(),
            'last_checked_at' => now(),
            'password_hint' => null,
            'last_error' => null,
        ]);
        $events->info(EventKind::Login, 'Account connected', ['phone' => Phone::mask($account->phone)], $account->id, userId: $account->user_id);
    }
}
