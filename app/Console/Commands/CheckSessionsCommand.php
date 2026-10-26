<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AccountStatus;
use App\Jobs\Accounts\CheckAccountSession;
use App\Models\UserPhone;
use Illuminate\Console\Command;

/** Queues a session check for every connected account, so dead sessions show up before a send. */
final class CheckSessionsCommand extends Command
{
    protected $signature = 'telegram:check-sessions';

    protected $description = 'Check that every authorized account still has a working session';

    public function handle(): int
    {
        $count = 0;
        UserPhone::query()
            ->where('status', AccountStatus::Authorized)
            ->whereNotNull('session_path')
            ->pluck('id')
            ->each(function (int $id) use (&$count): void {
                CheckAccountSession::dispatch($id)->delay(now()->addSeconds($count * 20));
                $count++;
            });

        $this->info("{$count} session checks queued.");

        return self::SUCCESS;
    }
}
