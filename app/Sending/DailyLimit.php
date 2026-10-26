<?php

declare(strict_types=1);

namespace App\Sending;

use App\Enums\MessageStatus;
use App\Enums\TaskStatus;
use App\Models\MessageGroup;
use App\Models\MessageTask;
use App\Models\TelegramMessage;
use App\Settings\Settings;

/** How many messages an account sent today, against the daily limit from the settings. */
final class DailyLimit
{
    public function __construct(private readonly Settings $settings) {}

    public function limit(): int
    {
        return max(0, $this->settings->int('daily_limit_per_phone'));
    }

    public function sentToday(int $phoneId): int
    {
        $since = now()->startOfDay();

        $messages = TelegramMessage::query()
            ->where('status', MessageStatus::Sent)
            ->where('sent_at', '>=', $since)
            ->whereIn('message_group_id', MessageGroup::query()->select('id')->where('user_phone_id', $phoneId))
            ->count();

        $tasks = MessageTask::query()
            ->where('sender_phone_id', $phoneId)
            ->where('status', TaskStatus::Sent)
            ->where('sent_at', '>=', $since)
            ->count();

        return $messages + $tasks;
    }

    public function reached(int $phoneId): bool
    {
        return $this->limit() > 0 && $this->sentToday($phoneId) >= $this->limit();
    }
}
