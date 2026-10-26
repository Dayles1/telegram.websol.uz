<?php

namespace App\Jobs\V2;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendMassMessagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
    public $backoff = [60, 300, 600];

    protected int $groupId;
    protected int $batchNo;

    public function __construct(int $groupId, int $batchNo)
    {
        $this->groupId = $groupId;
        $this->batchNo = $batchNo;
    }


    public function handle(): void
    {
        $php = '/opt/php83/bin/php';
            $artisan = base_path('artisan');
            $command = "nohup {$php} {$artisan} telegram:send-batch-messages {$this->groupId}  {$this->batchNo} > /dev/null 2>&1 &";
            exec($command);
    }
}
