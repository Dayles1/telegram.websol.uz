<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Sending\SendWorker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * Sends what is due for one account for up to worker.max_runtime seconds.
 *
 * At most one of these exists per account (ShouldBeUnique) and the worker holds
 * the account's ProcessLock, so a session is never opened twice. When time is up
 * with messages left, the job puts itself back on the queue; a failing account
 * comes back with growing delays. The minute tick queues it for every account with
 * due messages, so nothing waits forever if a job is lost.
 *
 * It shares the one queue with logins and checks, so it takes one of the
 * WorkerSlots and the rest of the worker processes stay free for them.
 */
final class SendAccountMessages implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Putting itself back on the queue is how the job continues, not a failure. */
    public int $tries = 0;

    /** Unexpected exceptions (the database is down ...) fail the job after three. */
    public int $maxExceptions = 3;

    public int $timeout;

    /**
     * A run killed at the timeout (a Telegram call that hung) ends the job and frees
     * the account at once; the minute tick queues it again. Otherwise the job would
     * sit reserved until the queue's retry_after and the account's other messages
     * would wait with it.
     */
    public bool $failOnTimeout = true;

    /** A lock left by a killed process expires on its own. */
    public int $uniqueFor = 3600;

    public function __construct(public readonly int $accountId)
    {
        $this->onQueue(config('telegram.queue'));
        $this->timeout = (int) config('telegram.worker.max_runtime') + 120;
    }

    public function uniqueId(): string
    {
        return (string) $this->accountId;
    }

    public function handle(SendWorker $worker): void
    {
        $slot = WorkerSlots::take($this->timeout);
        if ($slot === null) {
            $this->release(WorkerSlots::RETRY_SECONDS);

            return;
        }

        $previous = $this->stopOnSigterm($worker);

        try {
            $outcome = $worker->run($this->accountId, (int) config('telegram.worker.max_runtime'));
        } finally {
            $slot->release();
            if ($previous !== null) {
                pcntl_signal(SIGTERM, $previous);
            }
        }

        match ($outcome) {
            SendWorker::MORE => $this->release(WorkerSlots::wanted() ? max($worker->resumeIn(), WorkerSlots::YIELD_SECONDS) : $worker->resumeIn()),
            SendWorker::RETRY => $this->release($this->retryDelay()),
            SendWorker::BUSY => $this->release(30),
            default => Cache::forget(self::failuresKey($this->accountId)),
        };
    }

    /** Failed runs in a row for this account (backoff, monitoring). */
    public static function failuresKey(int $accountId): string
    {
        return "telegram:send-failures:{$accountId}";
    }

    /**
     * 10 s, 30 s, 1 min ... for an account whose sends keep failing.
     * Not named backoff(): Laravel calls a job's backoff() on every dispatch.
     */
    private function retryDelay(): int
    {
        $key = self::failuresKey($this->accountId);
        Cache::add($key, 0, 3600);
        $failures = (int) Cache::increment($key);
        $steps = (array) config('telegram.worker.backoff');

        return (int) $steps[min(max($failures, 1), count($steps)) - 1];
    }

    /**
     * A deploy (supervisorctl stop, queue worker SIGTERM) lets the message in hand
     * finish, then the job ends and comes back after the restart.
     *
     * @return callable|int|null the handler to restore afterwards
     */
    private function stopOnSigterm(SendWorker $worker): callable|int|null
    {
        if (! extension_loaded('pcntl')) {
            return null;
        }

        $previous = pcntl_signal_get_handler(SIGTERM);
        pcntl_signal(SIGTERM, function (int $signal, mixed $info = null) use ($worker, $previous): void {
            $worker->stop();
            if (is_callable($previous)) {
                $previous($signal, $info);
            }
        });

        return $previous;
    }
}
