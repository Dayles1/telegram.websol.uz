<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DailyFetch extends Command
{
    protected $signature = 'start:fetching';
    protected $description = 'Command description';

    public function handle()
    {Log::info('Daily fetching command executed.');
        
        $groups = \App\Models\ChatLogGroup::where('status', 'fetching')->get();
        $groupCount = $groups->count();
        Log::info("Found {$groupCount} ChatLogGroups with status 'fetching'.");
        foreach ($groups as $group) {
            \App\Jobs\FetchChatMessagesJob::dispatch($group->user_phone_id, $group->peer, 200);
            Log::info("Dispatched FetchChatMessagesJob for ChatLogGroup ID: {$group->id}");
            sleep(60);
        }

    }
}
