<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * The queue worker processes long Telegram jobs may hold at once: sends and chat
 * history, at most worker.send_slots of them. Login steps never take one, so with
 * numprocs = send_slots + 1 a login code never waits behind a send or a history
 * fetch (a fetch alone can hold a process for five minutes).
 */
final class WorkerSlots
{
    /** A slot of a killed process frees itself after $seconds (the job's timeout). */
    public static function take(int $seconds): ?Lock
    {
        for ($i = 0; $i < max(1, (int) config('telegram.worker.send_slots')); $i++) {
            $lock = Cache::lock("telegram:send-slot:{$i}", $seconds);
            if ($lock->get()) {
                return $lock;
            }
        }

        return null;
    }
}
