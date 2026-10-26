<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status of one message to one peer (telegram_messages.status).
 *
 * pending -> sending (claimed by a worker) -> sent | failed
 * pending -> canceled
 * sending -> pending (flood wait, or the claim went stale)
 */
enum MessageStatus: string
{
    case Pending = 'pending';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Canceled = 'canceled';

    public function isFinal(): bool
    {
        return in_array($this, [self::Sent, self::Failed, self::Canceled], true);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Sent => 'success',
            self::Failed => 'error',
            self::Canceled => 'neutral',
            self::Sending => 'brand',
            self::Pending => 'info',
        };
    }

    public function label(): string
    {
        return __("enums.message_status.{$this->value}");
    }
}
