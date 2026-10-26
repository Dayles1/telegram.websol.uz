<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Enums\EventKind;
use App\Enums\EventLevel;
use App\Models\SystemEvent;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes the journal shown on the monitoring pages (system_events) and mirrors
 * every entry to the "telegram" log channel.
 *
 * Warnings and errors with the same fingerprint fold into one open row
 * (occurrences + last_seen_at). Recording never throws: a broken journal must not
 * break the work being journaled.
 */
final class EventRecorder
{
    public function __construct(private readonly AlertNotifier $alerts) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(
        EventKind $kind,
        EventLevel $level,
        string $message,
        array $context = [],
        ?int $phoneId = null,
        ?int $groupId = null,
        ?int $userId = null,
        ?string $errorKey = null,
        ?string $fingerprint = null,
    ): ?SystemEvent {
        $context = array_filter($context, fn ($v) => $v !== null);
        Log::channel('telegram')->log(
            $level === EventLevel::Critical ? 'critical' : $level->value,
            $message,
            ['kind' => $kind->value, 'phone_id' => $phoneId, 'group_id' => $groupId, 'error_key' => $errorKey] + $context,
        );

        try {
            $fingerprint ??= sha1(implode('|', [$kind->value, $level->value, $errorKey ?? $message, $phoneId]));
            $now = now();

            $event = $level->folds()
                ? SystemEvent::query()->open()->where('fingerprint', $fingerprint)->latest('id')->first()
                : null;

            if ($event !== null) {
                $event->forceFill([
                    'occurrences' => $event->occurrences + 1,
                    'last_seen_at' => $now,
                    'message' => $message,
                    'context' => $context ?: $event->context,
                    'message_group_id' => $groupId ?? $event->message_group_id,
                ])->save();
            } else {
                $event = SystemEvent::query()->create([
                    'level' => $level,
                    'kind' => $kind,
                    'message' => mb_substr($message, 0, 255),
                    'error_key' => $errorKey,
                    'user_id' => $userId,
                    'user_phone_id' => $phoneId,
                    'message_group_id' => $groupId,
                    'context' => $context ?: null,
                    'fingerprint' => $fingerprint,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                ]);
            }

            $this->alerts->maybeNotify($event);

            return $event;
        } catch (Throwable $e) {
            Log::error('Event journal write failed', ['exception' => $e->getMessage(), 'event' => $message]);

            return null;
        }
    }

    /** @param array<string, mixed> $context */
    public function info(EventKind $kind, string $message, array $context = [], ?int $phoneId = null, ?int $groupId = null, ?int $userId = null): ?SystemEvent
    {
        return $this->record($kind, EventLevel::Info, $message, $context, $phoneId, $groupId, $userId);
    }

    /** @param array<string, mixed> $context */
    public function warning(EventKind $kind, string $message, array $context = [], ?int $phoneId = null, ?int $groupId = null, ?string $errorKey = null): ?SystemEvent
    {
        return $this->record($kind, EventLevel::Warning, $message, $context, $phoneId, $groupId, errorKey: $errorKey);
    }

    /** @param array<string, mixed> $context */
    public function error(EventKind $kind, string $message, array $context = [], ?int $phoneId = null, ?int $groupId = null, ?string $errorKey = null): ?SystemEvent
    {
        return $this->record($kind, EventLevel::Error, $message, $context, $phoneId, $groupId, errorKey: $errorKey);
    }

    /** @param array<string, mixed> $context */
    public function critical(EventKind $kind, string $message, array $context = [], ?int $phoneId = null, ?string $errorKey = null): ?SystemEvent
    {
        return $this->record($kind, EventLevel::Critical, $message, $context, $phoneId, errorKey: $errorKey);
    }
}
