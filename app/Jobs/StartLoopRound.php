<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Sending\MassSend;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Starts the next round of a repeating send. Queued with a delay when a round
 * finishes; only database work, no Telegram. The minute tick starts overdue rounds
 * too, so a lost job only delays a round by a minute.
 */
final class StartLoopRound implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $loopId) {}

    public function handle(MassSend $send): void
    {
        $send->startRound($this->loopId);
    }
}
