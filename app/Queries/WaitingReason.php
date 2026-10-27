<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\EventKind;
use App\Enums\MessageStatus;
use App\Jobs\SendAccountMessages;
use App\Models\MessageGroup;
use App\Models\SystemEvent;
use App\Models\UserPhone;
use App\Monitoring\QueueHealth;
use App\Sending\DailyLimit;
use App\Sending\SendWorker;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Why the queued messages of a send are not going out right now: the account is
 * not connected, paused, out of today's limit, its runs keep failing or hanging,
 * the queue worker is down, or simply waiting for its turn. Plus the account's
 * last problem from the journal, which usually names the cause.
 *
 * Read only; the send page polls it.
 */
final class WaitingReason
{
    /** Problems of the account the journal keeps that explain a stuck queue. */
    private const ACCOUNT_KINDS = [EventKind::Worker, EventKind::Session, EventKind::Flood];

    public function __construct(private readonly DailyLimit $dailyLimit) {}

    /**
     * Needs withDeliveryCounts() on $group. Null when nothing waits or it is being sent now.
     *
     * @return array{key: string, tone: string, params: array<string, string|int>, problem: ?array<string, mixed>}|null
     */
    public function of(MessageGroup $group): ?array
    {
        if (! $group->status->isActive() || (int) $group->pending_count - (int) $group->sending_count <= 0) {
            return null;
        }

        $phone = UserPhone::query()->find($group->user_phone_id);
        $reason = $phone === null ? ['account_missing', 'error', []] : $this->reason($phone, $group);

        return $reason === null ? null : [
            'key' => $reason[0],
            'tone' => $reason[1],
            'params' => $reason[2],
            'problem' => $phone !== null ? $this->lastProblem($phone) : null,
        ];
    }

    /** @return array{0: string, 1: string, 2: array<string, string|int>}|null */
    private function reason(UserPhone $phone, MessageGroup $group): ?array
    {
        if (! $phone->canSend()) {
            return ['account_not_ready', 'error', ['status' => $phone->status->label()]];
        }
        if ($phone->isFlooded()) {
            $key = match ($phone->last_error) {
                'peer_flood' => 'peer_flood',
                'account_frozen' => 'frozen',
                'worker_timeout' => 'paused_hanging',
                default => 'flood_wait',
            };

            return [$key, $key === 'frozen' ? 'error' : 'warning', ['until' => $this->moment($phone->flood_until)]];
        }
        if (! $this->queueAlive()) {
            return ['queue_down', 'error', []];
        }
        if ($this->dailyLimit->reached($phone->id)) {
            return ['daily_limit', 'warning', ['limit' => $this->dailyLimit->limit()]];
        }

        $running = Cache::get(SendWorker::runningKey($phone->id));
        if (is_array($running)) {
            $seconds = time() - (int) $running['started_at'];

            // Past the run's own limit the process is stuck on a Telegram call (or already killed).
            return $seconds > (int) config('telegram.worker.max_runtime') + 30
                ? ['hanging', 'warning', ['minutes' => intdiv($seconds, 60)]]
                : null;
        }
        if (($deadRuns = (int) Cache::get(SendWorker::deadRunsKey($phone->id), 0)) > 0) {
            return ['hung_before', 'warning', ['n' => $deadRuns]];
        }
        if (($failures = (int) Cache::get(SendAccountMessages::failuresKey($phone->id), 0)) > 0) {
            return ['retrying', 'warning', ['n' => $failures]];
        }

        $pending = $group->messages()->where('status', MessageStatus::Pending);
        $nextAt = (clone $pending)->min('send_at');
        $dueNow = (clone $pending)->where(fn ($q) => $q->whereNull('send_at')->orWhere('send_at', '<=', now()))->exists();
        if ($nextAt !== null && ! $dueNow) {
            return ['scheduled', 'info', ['at' => $this->moment(Carbon::parse($nextAt))]];
        }

        return ['waiting_turn', 'info', []];
    }

    /** @return array<string, mixed>|null */
    private function lastProblem(UserPhone $phone): ?array
    {
        $event = SystemEvent::query()
            ->problems()
            ->open()
            ->where('user_phone_id', $phone->id)
            ->where(fn ($q) => $q->whereIn('kind', self::ACCOUNT_KINDS)->orWhere('error_key', 'unknown_error'))
            ->where('last_seen_at', '>=', now()->subDay())
            ->latest('last_seen_at')
            ->first();

        if ($event === null) {
            return null;
        }

        $label = $event->error_key !== null && trans()->has("errors.codes.{$event->error_key}")
            ? __("errors.codes.{$event->error_key}")
            : null;

        return [
            'level' => $event->level->value,
            'label' => $label,
            'message' => $event->message,
            'raw' => isset($event->context['raw']) ? mb_substr((string) $event->context['raw'], 0, 300) : null,
            'occurrences' => $event->occurrences,
            'at' => $event->last_seen_at->toIso8601String(),
        ];
    }

    private function queueAlive(): bool
    {
        $beat = QueueHealth::lastBeat();

        return $beat !== null && time() - $beat < 120;
    }

    private function moment(?DateTimeInterface $at): string
    {
        if ($at === null) {
            return '';
        }
        $at = Carbon::instance($at)->setTimezone(config('app.timezone'));

        return $at->isToday() ? $at->format('H:i') : $at->format('d.m H:i');
    }
}
