<?php

declare(strict_types=1);

namespace App\Telegram;

use Illuminate\Support\Facades\File;

/**
 * An exclusive, non-blocking lock held for the life of a process (flock).
 *
 * Two full MadelineProto processes on one session must never run at once: the
 * second silently turns into an IPC client of the first. The OS releases the
 * lock when the process dies, so a crash never leaves a stale lock behind.
 */
final class ProcessLock
{
    /** @var resource|null */
    private $handle = null;

    private function __construct(private readonly string $path) {}

    public static function for(string $name): self
    {
        $dir = storage_path('framework/telegram-locks');
        File::ensureDirectoryExists($dir);

        return new self($dir.DIRECTORY_SEPARATOR.preg_replace('/[^A-Za-z0-9_\-]/', '_', $name).'.lock');
    }

    public function acquire(): bool
    {
        $handle = fopen($this->path, 'c+');
        if ($handle === false) {
            return false;
        }
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        ftruncate($handle, 0);
        fwrite($handle, (string) getmypid());
        fflush($handle);
        $this->handle = $handle;

        return true;
    }

    /** Tries again every quarter second for up to $seconds. */
    public function acquireWithin(int $seconds): bool
    {
        $until = microtime(true) + $seconds;
        while (! $this->acquire()) {
            if (microtime(true) >= $until) {
                return false;
            }
            usleep(250_000);
        }

        return true;
    }

    /** True when some process holds the lock right now. */
    public function isHeld(): bool
    {
        if ($this->handle !== null) {
            return true;
        }
        if (! $this->acquire()) {
            return true;
        }
        $this->release();

        return false;
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
