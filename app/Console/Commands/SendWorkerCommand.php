<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Sending\SendWorker;
use Illuminate\Console\Command;

/**
 * Sends the due messages of one account in the foreground, for debugging.
 * Production sends through the queue (SendAccountMessages).
 */
final class SendWorkerCommand extends Command
{
    protected $signature = 'telegram:send-worker {account : user_phones.id}';

    protected $description = 'Send the due messages of one Telegram account now, in this process';

    public function handle(SendWorker $worker): int
    {
        if (extension_loaded('pcntl')) {
            $this->trap([SIGTERM, SIGINT], fn () => $worker->stop());
        }

        return $worker->run((int) $this->argument('account'), (int) config('telegram.worker.max_runtime'));
    }
}
