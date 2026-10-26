<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Sending\SendScheduler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * The project's clock, without cron: the queue worker queues this job once a
 * minute (dispatchIfDue() on every loop of the worker, AppServiceProvider).
 * It keeps sending moving (SendScheduler::tick) and runs the daily chores.
 * Only database work and dispatches, no Telegram.
 */
final class MinuteTick implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Once a day, the first tick at or after this time (app timezone). */
    private const DAILY = [
        'chat-logs:refetch' => ['00:00', []],
        'monitoring:prune' => ['03:30', []],
        'queue:prune-failed' => ['04:00', ['--hours' => 720]],
        'telegram:check-sessions' => ['06:00', []],
    ];

    public int $tries = 1;

    public int $timeout = 120;

    /** Busy workers never pile up ticks; the next one comes a minute later anyway. */
    public int $uniqueFor = 300;

    public function __construct()
    {
        $this->onQueue(config('telegram.queue'));
    }

    /** Called on every loop of every worker: queues a tick at most once a minute (one cache read while not due). */
    public static function dispatchIfDue(): void
    {
        if (Cache::add('telegram:minute-tick:queued', true, 60)) {
            self::dispatch();
        }
    }

    public function handle(SendScheduler $scheduler): void
    {
        $scheduler->tick();

        foreach (self::DAILY as $command => [$at, $parameters]) {
            if (now()->format('H:i') >= $at && Cache::add("telegram:daily:{$command}:".now()->toDateString(), true, 60 * 60 * 26)) {
                Artisan::call($command, $parameters);
            }
        }
    }
}
