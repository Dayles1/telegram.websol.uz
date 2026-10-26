<?php

declare(strict_types=1);

namespace App\Jobs\Accounts;

use App\Accounts\AccountLogin;
use App\Enums\AccountStatus;
use App\Enums\EventKind;
use App\Monitoring\EventRecorder;
use App\Telegram\Contracts\TelegramGateway;
use App\Telegram\Phone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

final class SubmitLoginPassword extends AccountJob
{
    public function handle(TelegramGateway $telegram, EventRecorder $events): void
    {
        $account = $this->account();
        if ($account === null || $account->status !== AccountStatus::Verifying) {
            return;
        }

        $encrypted = Cache::pull(AccountLogin::passwordCacheKey($account->id));
        if ($encrypted === null) {
            // The five minutes passed before a worker picked the job up: ask again.
            $account->update(['status' => AccountStatus::NeedPassword, 'last_error' => 'password_expired']);

            return;
        }

        try {
            $profile = $telegram->submitPassword($account->session_path, Crypt::decryptString($encrypted));
        } catch (Throwable $e) {
            $this->fail($account, $e, 'password');

            return;
        }

        $account->update($profile->toColumns() + [
            'status' => AccountStatus::Authorized,
            'authorized_at' => now(),
            'last_checked_at' => now(),
            'password_hint' => null,
            'last_error' => null,
        ]);
        $events->info(EventKind::Login, 'Account connected', ['phone' => Phone::mask($account->phone)], $account->id, userId: $account->user_id);
    }
}
