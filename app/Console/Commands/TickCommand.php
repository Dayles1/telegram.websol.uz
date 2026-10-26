<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Sending\SendScheduler;
use Illuminate\Console\Command;

/** Every minute from the scheduler: repairs, overdue repeat rounds, queues due sends, deletes unused files. */
final class TickCommand extends Command
{
    protected $signature = 'telegram:tick';

    protected $description = 'Keep sending moving: sweep, start due repeat rounds, queue accounts with due messages, delete unused files';

    public function handle(SendScheduler $scheduler): int
    {
        $result = $scheduler->tick();
        $this->line(sprintf('rounds started: %d, accounts queued: %d, files deleted: %d', $result['rounds'], $result['queued'], $result['files_deleted']));

        return self::SUCCESS;
    }
}
