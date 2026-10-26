<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status of a mass send (message_groups.status).
 *
 * pending -> sending -> completed (at least one message went out) | failed (none did)
 * pending|sending -> canceled
 *
 * "sent", "processing" and "infinity_pending" are values the old code wrote; they are read,
 * never written. "processing" counts as active, so the supervisor's sweep gives it a final status.
 */
enum GroupStatus: string
{
    case Pending = 'pending';
    case Sending = 'sending';
    case Completed = 'completed';
    case Failed = 'failed';
    case Canceled = 'canceled';
    case LegacySent = 'sent';
    case LegacyInfinity = 'infinity_pending';
    case LegacyProcessing = 'processing';

    /** @return list<string> */
    public static function activeValues(): array
    {
        return [self::Pending->value, self::Sending->value, self::LegacyProcessing->value];
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Pending, self::Sending, self::LegacyProcessing], true);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Completed, self::LegacySent => 'success',
            self::Failed => 'error',
            self::Canceled, self::LegacyInfinity => 'neutral',
            self::Sending, self::LegacyProcessing => 'brand',
            self::Pending => 'info',
        };
    }

    public function label(): string
    {
        return __("enums.group_status.{$this->value}");
    }
}
