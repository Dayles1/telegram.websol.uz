<?php

declare(strict_types=1);

namespace App\Sending;

use App\Enums\AccountStatus;
use App\Enums\EventKind;
use App\Enums\GroupStatus;
use App\Enums\LoopStatus;
use App\Enums\MessageStatus;
use App\Enums\TaskStatus;
use App\Jobs\SendAccountMessages;
use App\Models\MessageGroup;
use App\Models\MessageTask;
use App\Models\SendLoop;
use App\Models\TelegramMessage;
use App\Models\UserPhone;
use App\Monitoring\EventRecorder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Keeps sending moving without a long-running process of our own:
 * telegram:tick runs tick() every minute from the scheduler.
 *
 * Sends are queued when they are created; this is the safety net: it repairs
 * what crashed work left behind, starts overdue rounds of repeating sends,
 * queues a send job for every account with due messages (a job already queued
 * or running is not queued twice), and deletes files nobody will send again.
 */
final class SendScheduler
{
    /** Last tick, for the monitoring page. */
    public const TICK_KEY = 'telegram:tick';

    public function __construct(
        private readonly GroupProgress $progress,
        private readonly DailyLimit $dailyLimit,
        private readonly MassSend $send,
        private readonly Attachments $attachments,
        private readonly EventRecorder $events,
    ) {}

    /** @return array{rounds: int, queued: int, files_deleted: int} */
    public function tick(): array
    {
        $this->sweep();
        $result = [
            'rounds' => $this->startDueRounds(),
            'queued' => $this->queueDue(),
            'files_deleted' => $this->attachments->cleanup(),
        ];
        Cache::put(self::TICK_KEY, ['at' => time()] + $result, 3600);

        return $result;
    }

    /** Accounts that have something to send now. @return list<int> */
    public function dueAccounts(): array
    {
        $sendable = UserPhone::query()
            ->where('status', AccountStatus::Authorized)
            ->whereNotNull('session_path')
            ->where(fn ($q) => $q->whereNull('flood_until')->orWhere('flood_until', '<=', now()));

        $withMessages = MessageGroup::query()
            ->whereIn('status', GroupStatus::activeValues())
            ->whereIn('user_phone_id', (clone $sendable)->select('id'))
            ->whereExists(fn ($q) => $q->from('telegram_messages')
                ->whereColumn('telegram_messages.message_group_id', 'message_groups.id')
                ->where('telegram_messages.status', MessageStatus::Pending->value))
            ->distinct()
            ->pluck('user_phone_id');

        $withTasks = MessageTask::query()
            ->where('status', TaskStatus::Pending)
            ->whereIn('sender_phone_id', (clone $sendable)->select('id'))
            ->distinct()
            ->pluck('sender_phone_id');

        return $withMessages->merge($withTasks)->map(fn ($id) => (int) $id)->unique()
            ->reject(fn (int $id) => $this->dailyLimit->reached($id))
            ->values()
            ->all();
    }

    public function queueDue(): int
    {
        $accounts = $this->dueAccounts();
        foreach ($accounts as $accountId) {
            SendAccountMessages::dispatch($accountId);
        }

        return count($accounts);
    }

    public function startDueRounds(): int
    {
        return SendLoop::query()
            ->where('status', LoopStatus::Active)
            ->where('next_run_at', '<=', now())
            ->pluck('id')
            ->filter(fn (int $id) => $this->send->startRound($id) !== null)
            ->count();
    }

    /** Repairs what crashed or abandoned work leaves behind. Safe to run any time. */
    public function sweep(): void
    {
        $staleBefore = now()->subMinutes((int) config('telegram.worker.stale_claim_minutes'));

        // A worker died mid-message: the row goes back to pending (or fails after max attempts).
        $stale = TelegramMessage::query()->where('status', MessageStatus::Sending)->where('claimed_at', '<', $staleBefore);
        (clone $stale)->where('attempts', '>=', (int) config('telegram.worker.max_attempts'))
            ->update(['status' => MessageStatus::Failed, 'error_key' => 'worker_lost', 'updated_at' => now()]);
        $released = $stale->update(['status' => MessageStatus::Pending, 'claimed_at' => null, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);

        MessageTask::query()->where('status', TaskStatus::Processing)->where('updated_at', '<', $staleBefore)
            ->update(['status' => TaskStatus::Failed, 'error_code' => 'WORKER_LOST', 'updated_at' => now()]);

        // Rows of accounts that can't send any more would wait forever.
        $deadAccounts = UserPhone::query()->whereNotIn('status', [AccountStatus::Authorized->value])->select('id');
        $orphaned = TelegramMessage::query()
            ->where('status', MessageStatus::Pending)
            ->whereIn('message_group_id', MessageGroup::query()->select('id')->whereIn('user_phone_id', $deadAccounts))
            ->update(['status' => MessageStatus::Failed, 'error_key' => 'session_revoked', 'updated_at' => now()]);
        MessageTask::query()->where('status', TaskStatus::Pending)->whereIn('sender_phone_id', $deadAccounts)
            ->update(['status' => TaskStatus::Failed, 'error_code' => 'SENDER_NOT_LOGGED_IN', 'updated_at' => now()]);

        // Too old to be worth sending (and old rows the previous version left pending).
        $expiredBefore = now()->subHours((int) config('telegram.worker.expire_pending_hours'));
        $expired = TelegramMessage::query()
            ->where('status', MessageStatus::Pending)
            ->where('created_at', '<', $expiredBefore)
            ->update(['status' => MessageStatus::Failed, 'error_key' => 'expired', 'updated_at' => now()]);
        MessageTask::query()->where('status', TaskStatus::Pending)->where('created_at', '<', $expiredBefore)
            ->update(['status' => TaskStatus::Failed, 'error_code' => 'EXPIRED', 'updated_at' => now()]);

        // Groups with nothing left to send get their final status.
        MessageGroup::query()
            ->whereIn('status', GroupStatus::activeValues())
            ->whereNotExists(fn ($q) => $q->from('telegram_messages')
                ->whereColumn('telegram_messages.message_group_id', 'message_groups.id')
                ->whereIn('telegram_messages.status', [MessageStatus::Pending->value, MessageStatus::Sending->value]))
            ->pluck('id')
            ->each(fn (int $id) => $this->progress->refresh($id));

        if ($released > 0 || $orphaned > 0 || $expired > 0) {
            $this->events->warning(EventKind::Worker, 'Sweep repaired abandoned messages', [
                'released' => $released,
                'failed_orphans' => $orphaned,
                'expired' => $expired,
            ], errorKey: 'sweep_repair');
        }
    }

    /**
     * Send jobs running right now and accounts whose job keeps failing, for the
     * monitoring page.
     *
     * @return array{running: list<array{account_id: int, pid: ?int, started_at: int}>, backoff: list<array{account_id: int, failures: int}>}
     */
    public function jobs(): array
    {
        $candidates = collect($this->dueAccounts())
            ->merge(MessageGroup::query()
                ->whereIn('id', TelegramMessage::query()->select('message_group_id')->where('status', MessageStatus::Sending))
                ->pluck('user_phone_id'))
            ->map(fn ($id) => (int) $id)
            ->unique();

        $running = [];
        $backoff = [];
        foreach ($candidates as $id) {
            if (is_array($job = Cache::get(SendWorker::runningKey($id)))) {
                $running[] = ['account_id' => $id, 'pid' => $job['pid'] ?? null, 'started_at' => (int) $job['started_at']];
            }
            if (($failures = (int) Cache::get(SendAccountMessages::failuresKey($id), 0)) > 0) {
                $backoff[] = ['account_id' => $id, 'failures' => $failures];
            }
        }

        return ['running' => $running, 'backoff' => $backoff];
    }
}
