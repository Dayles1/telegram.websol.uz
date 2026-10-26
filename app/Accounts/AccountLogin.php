<?php

declare(strict_types=1);

namespace App\Accounts;

use App\Enums\AccountStatus;
use App\Enums\EventKind;
use App\Exceptions\ActionRefused;
use App\Jobs\Accounts\RequestLoginCode;
use App\Jobs\Accounts\SubmitLoginCode;
use App\Jobs\Accounts\SubmitLoginPassword;
use App\Models\User;
use App\Models\UserPhone;
use App\Monitoring\EventRecorder;
use App\Telegram\Phone;
use App\Telegram\SessionStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * The web/API side of connecting a Telegram account. Each step only moves the
 * user_phones row to its next state and queues a job; the job talks to Telegram
 * and writes the outcome back. The UI polls the row.
 *
 *   start(phone)       -> requesting_code  -> (job) code_sent | failed
 *   submitCode(code)   -> verifying        -> (job) authorized | need_password | code_invalid | failed
 *   submitPassword(pw) -> verifying        -> (job) authorized | password_invalid | failed
 *   cancel()           -> logged_out (a never-finished login is deleted)
 */
final class AccountLogin
{
    public const PASSWORD_TTL_SECONDS = 300;

    public function __construct(
        private readonly SessionStore $sessions,
        private readonly EventRecorder $events,
    ) {}

    public function start(User $user, string $phoneInput): UserPhone
    {
        $phone = Phone::normalize($phoneInput);
        if (strlen($phone) < 10) {
            throw new ActionRefused('phone_invalid', status: 422);
        }

        $account = DB::transaction(function () use ($user, $phone): UserPhone {
            $takenByOther = UserPhone::query()
                ->wherePhone($phone)
                ->where('user_id', '!=', $user->id)
                ->where('status', AccountStatus::Authorized)
                ->exists();
            if ($takenByOther) {
                throw new ActionRefused('phone_taken');
            }

            /** @var UserPhone|null $account */
            $account = UserPhone::query()->wherePhone($phone)->where('user_id', $user->id)->lockForUpdate()->first();

            if ($account !== null && ! $account->status->canStartLogin() && ! $account->isStaleLogin()) {
                throw new ActionRefused($account->status === AccountStatus::Authorized ? 'already_authorized' : 'login_in_progress');
            }

            $account ??= new UserPhone(['user_id' => $user->id, 'phone' => $phone]);
            $account->fill([
                'status' => AccountStatus::RequestingCode,
                'session_path' => $this->sessions->pathFor($phone, $user->id),
                'password_hint' => null,
                'last_error' => null,
                'login_started_at' => now(),
            ])->save();

            return $account;
        });

        RequestLoginCode::dispatch($account->id)->afterCommit();
        $this->events->info(EventKind::Login, 'Login started', ['phone' => Phone::mask($phone)], $account->id, userId: $user->id);

        return $account;
    }

    public function submitCode(UserPhone $account, string $code): UserPhone
    {
        $account = $this->moveToVerifying($account, fn (AccountStatus $s) => $s->acceptsCode(), 'code_not_expected');
        SubmitLoginCode::dispatch($account->id, preg_replace('/\D+/', '', $code))->afterCommit();

        return $account;
    }

    public function submitPassword(UserPhone $account, string $password): UserPhone
    {
        $account = $this->moveToVerifying($account, fn (AccountStatus $s) => $s->acceptsPassword(), 'password_not_expected');

        // The password never travels in a job payload or a log: encrypted, in the cache, for five minutes.
        Cache::put(self::passwordCacheKey($account->id), Crypt::encryptString($password), self::PASSWORD_TTL_SECONDS);
        SubmitLoginPassword::dispatch($account->id)->afterCommit();

        return $account;
    }

    /** Abandons an unfinished login. A phone that never logged in disappears. */
    public function cancel(UserPhone $account): void
    {
        DB::transaction(function () use ($account): void {
            $account = UserPhone::query()->lockForUpdate()->findOrFail($account->id);
            if ($account->status === AccountStatus::Authorized) {
                throw new ActionRefused('already_authorized');
            }

            $this->sessions->delete($account->session_path);

            if ($account->authorized_at === null && ! $account->messageGroups()->exists()) {
                $account->delete();

                return;
            }

            $account->update(['status' => AccountStatus::LoggedOut, 'session_path' => null, 'password_hint' => null]);
        });
    }

    public static function passwordCacheKey(int $accountId): string
    {
        return "telegram-login-password:{$accountId}";
    }

    /** @param callable(AccountStatus): bool $accepts */
    private function moveToVerifying(UserPhone $account, callable $accepts, string $refusal): UserPhone
    {
        return DB::transaction(function () use ($account, $accepts, $refusal): UserPhone {
            $account = UserPhone::query()->lockForUpdate()->findOrFail($account->id);
            if (! $accepts($account->status)) {
                throw new ActionRefused($refusal);
            }

            $account->update(['status' => AccountStatus::Verifying, 'last_error' => null, 'login_started_at' => now()]);

            return $account;
        });
    }
}
