<?php

namespace App\Jobs;

use App\Models\MessageGroup;
use App\Models\UserPhone;
use danog\MadelineProto\API;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Logger as LoggerSettings;
use danog\MadelineProto\Logger;

class SendTelegramMessages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [60, 300, 600];

    protected int $groupId;

    public function __construct(int $groupId)
    {
        $this->groupId = $groupId;
    }

    public function handle()
    {

        // $php = '/opt/php83/bin/php';
        $php = config('app.php_bin');

            $artisan = base_path('artisan');
            $command = "nohup {$php} {$artisan} telegram:send-messages {$this->groupId} > /dev/null 2>&1 &";
            exec($command);
    }
}
