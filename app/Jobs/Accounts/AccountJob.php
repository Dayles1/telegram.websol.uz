<?php

declare(strict_types=1);

namespace App\Jobs\Accounts;

use App\Enums\AccountStatus;
use App\Enums\EventKind;
use App\Models\UserPhone;
use App\Monitoring\EventRecorder;
use App\Telegram\Errors\ErrorKind;
use App\Telegram\Errors\TelegramError;
use App\Telegram\Phone;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Shared shape of the login/logout jobs: one try (a login step must not be
 * replayed), a hard timeout, encrypted payload, and one way to record failure.
 */
abstract class AccountJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly int $accountId)
    {
        $this->onQueue(config('telegram.queue'));
    }

    protected function account(): ?UserPhone
    {
        return UserPhone::query()->find($this->accountId);
    }

    /** Writes a failed step on the account and in the journal. */
    protected function fail(UserPhone $account, Throwable|TelegramError $error, string $step, AccountStatus $status = AccountStatus::Failed): void
    {
        $error = $error instanceof TelegramError ? $error : TelegramError::from($error);

        $status = match ($error->kind) {
            ErrorKind::CodeInvalid => AccountStatus::CodeInvalid,
            ErrorKind::PasswordInvalid => AccountStatus::PasswordInvalid,
            default => $status,
        };

        $account->update(['status' => $status, 'last_error' => $error->storedKey()]);

        $recorder = app(EventRecorder::class);
        $context = ['step' => $step, 'phone' => Phone::mask($account->phone), 'raw' => $error->raw];
        in_array($status, [AccountStatus::CodeInvalid, AccountStatus::PasswordInvalid], true)
            ? $recorder->info(EventKind::Login, "Login {$step}: wrong input", $context, $account->id)
            : $recorder->warning(EventKind::Login, "Login {$step} failed", $context, $account->id, errorKey: $error->key);
    }

    /** The worker died or timed out: never leave the row "in flight". */
    public function failed(?Throwable $e): void
    {
        $account = $this->account();
        if ($account !== null && $account->status->isInFlight()) {
            $this->fail($account, $e ?? new \RuntimeException('job_failed'), class_basename($this));
        }
    }
}
