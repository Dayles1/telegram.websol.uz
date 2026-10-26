<?php

declare(strict_types=1);

namespace App\Monitoring;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Is a queue worker alive, and how much waits in the queue. */
final class QueueHealth
{
    private const KEY = 'queue:heartbeat';

    /** Throttled to one cache write per 10 seconds per worker. */
    public static function beat(): void
    {
        static $last = 0;
        if (time() - $last < 10) {
            return;
        }
        $last = time();
        Cache::put(self::KEY, time(), 600);
    }

    public static function lastBeat(): ?int
    {
        $beat = Cache::get(self::KEY);

        return $beat !== null ? (int) $beat : null;
    }

    /** @return array{alive: bool, last_beat: ?int, waiting: int, delayed: int, reserved: int, oldest_wait_seconds: ?int, failed: int} */
    public static function snapshot(): array
    {
        $now = time();
        $jobs = DB::table('jobs')
            ->selectRaw('SUM(CASE WHEN reserved_at IS NULL AND available_at <= ? THEN 1 ELSE 0 END) AS waiting', [$now])
            ->selectRaw('SUM(CASE WHEN reserved_at IS NULL AND available_at > ? THEN 1 ELSE 0 END) AS `delayed`', [$now])
            ->selectRaw('SUM(CASE WHEN reserved_at IS NOT NULL THEN 1 ELSE 0 END) AS reserved')
            ->selectRaw('MIN(CASE WHEN reserved_at IS NULL AND available_at <= ? THEN available_at END) AS oldest', [$now])
            ->first();

        $beat = self::lastBeat();

        return [
            'alive' => $beat !== null && $now - $beat < 120,
            'last_beat' => $beat,
            'waiting' => (int) ($jobs->waiting ?? 0),
            'delayed' => (int) ($jobs->delayed ?? 0),
            'reserved' => (int) ($jobs->reserved ?? 0),
            'oldest_wait_seconds' => $jobs->oldest ? $now - (int) $jobs->oldest : null,
            'failed' => DB::table('failed_jobs')->count(),
        ];
    }
}
