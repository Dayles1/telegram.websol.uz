<?php

declare(strict_types=1);

namespace App\Enums;

enum EventLevel: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
    case Critical = 'critical';

    public function weight(): int
    {
        return match ($this) {
            self::Info => 0,
            self::Warning => 1,
            self::Error => 2,
            self::Critical => 3,
        };
    }

    public function atLeast(self $other): bool
    {
        return $this->weight() >= $other->weight();
    }

    /** Warnings and worse are folded by fingerprint; info events are a plain journal. */
    public function folds(): bool
    {
        return $this !== self::Info;
    }

    public function tone(): string
    {
        return match ($this) {
            self::Info => 'info',
            self::Warning => 'warning',
            self::Error, self::Critical => 'error',
        };
    }

    public function label(): string
    {
        return __("enums.event_level.{$this->value}");
    }
}
