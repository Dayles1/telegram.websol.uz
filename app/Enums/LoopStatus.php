<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status of a repeating send (send_loops.status).
 *
 * active -> stopped (by a person, or the account can no longer send)
 * active -> finished (max_rounds reached)
 */
enum LoopStatus: string
{
    case Active = 'active';
    case Stopped = 'stopped';
    case Finished = 'finished';

    public function tone(): string
    {
        return match ($this) {
            self::Active => 'brand',
            self::Stopped => 'neutral',
            self::Finished => 'success',
        };
    }

    public function label(): string
    {
        return __("enums.loop_status.{$this->value}");
    }
}
