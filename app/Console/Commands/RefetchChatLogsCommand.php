<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\FetchChatHistory;
use App\Models\ChatLogGroup;
use Illuminate\Console\Command;

/** Re-reads every chat someone asked to follow (status "fetching"), one per minute. */
final class RefetchChatLogsCommand extends Command
{
    protected $signature = 'chat-logs:refetch';

    protected $description = 'Queue a history fetch for every followed chat';

    public function handle(): int
    {
        $count = 0;
        ChatLogGroup::query()->where('status', 'fetching')->pluck('id')->each(function (int $id) use (&$count): void {
            FetchChatHistory::dispatch($id)->delay(now()->addMinutes($count++));
        });

        $this->info("{$count} chats queued.");

        return self::SUCCESS;
    }
}
