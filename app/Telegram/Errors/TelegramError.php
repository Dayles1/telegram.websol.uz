<?php

declare(strict_types=1);

namespace App\Telegram\Errors;

use Throwable;

/**
 * A Telegram failure reduced to what the code needs: a stable key stored in the
 * database and translated in the UI (lang/*\/errors.php), what kind of problem it
 * is, and how long to wait when Telegram said so.
 */
final readonly class TelegramError
{
    public function __construct(
        public string $key,
        public ErrorKind $kind,
        public ?int $waitSeconds = null,
        public string $raw = '',
    ) {}

    public static function from(Throwable $e): self
    {
        return ErrorMap::classify($e);
    }

    /** The key with the wait appended, as the old rows store it: flood_wait_120. */
    public function storedKey(): string
    {
        return $this->waitSeconds !== null && $this->kind === ErrorKind::Wait
            ? "{$this->key}_{$this->waitSeconds}"
            : $this->key;
    }
}
