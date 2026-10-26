<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status of a single 1-to-1 message ordered through the API (telegram_message_tasks.status).
 */
enum TaskStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Sent = 'sent';
    case Failed = 'failed';
}
