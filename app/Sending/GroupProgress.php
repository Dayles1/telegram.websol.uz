<?php

declare(strict_types=1);

namespace App\Sending;

use App\Enums\EventKind;
use App\Enums\GroupStatus;
use App\Enums\LoopStatus;
use App\Enums\MessageStatus;
use App\Jobs\StartLoopRound;
use App\Models\MessageGroup;
use App\Models\SendLoop;
use App\Models\TelegramMessage;
use App\Monitoring\EventRecorder;
use Illuminate\Support\Facades\DB;

/**
 * The one place a group's status changes after creation. Pages only read it.
 * A finished round of a repeating send schedules the next one from here.
 */
final class GroupProgress
{
    public function __construct(private readonly EventRecorder $events) {}

    /** Called by the worker after every message, and by the sweep. */
    public function refresh(int $groupId): void
    {
        $group = MessageGroup::query()->find($groupId);
        if ($group === null || ! $group->status->isActive()) {
            return;
        }

        /** @var array<string, int> $counts */
        $counts = TelegramMessage::query()
            ->where('message_group_id', $groupId)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($n) => (int) $n)
            ->all();

        $open = ($counts[MessageStatus::Pending->value] ?? 0) + ($counts[MessageStatus::Sending->value] ?? 0);
        $sent = $counts[MessageStatus::Sent->value] ?? 0;
        $failed = $counts[MessageStatus::Failed->value] ?? 0;

        if ($open > 0) {
            if ($group->status === GroupStatus::Pending && ($sent + $failed) > 0) {
                $group->update(['status' => GroupStatus::Sending, 'started_at' => $group->started_at ?? now()]);
            }

            return;
        }

        $status = $sent > 0 ? GroupStatus::Completed : GroupStatus::Failed;
        $group->update(['status' => $status, 'started_at' => $group->started_at ?? now(), 'finished_at' => now()]);

        $context = ['sent' => $sent, 'failed' => $failed, 'canceled' => $counts[MessageStatus::Canceled->value] ?? 0];
        $status === GroupStatus::Failed && $failed > 0
            ? $this->events->warning(EventKind::Sending, 'Mass send finished without a single delivered message', $context, $group->user_phone_id, $group->id, 'group_failed')
            : $this->events->info(EventKind::Sending, 'Mass send finished', $context, $group->user_phone_id, $group->id);

        $this->scheduleNextRound($group);
    }

    /** The next round of a repeating send starts every_minutes after this one finished. */
    private function scheduleNextRound(MessageGroup $group): void
    {
        $loop = $group->send_loop_id !== null ? SendLoop::query()->find($group->send_loop_id) : null;
        if ($loop === null || ! $loop->isActive()) {
            return;
        }

        if ($loop->max_rounds !== null && $loop->rounds_done >= $loop->max_rounds) {
            $loop->update(['status' => LoopStatus::Finished, 'stopped_at' => now(), 'next_run_at' => null]);
            $this->events->info(EventKind::Sending, 'Repeating send finished its rounds', ['loop_id' => $loop->id, 'rounds' => $loop->rounds_done], $group->user_phone_id, $group->id);

            return;
        }

        $loop->update(['next_run_at' => now()->addMinutes($loop->every_minutes)]);
        StartLoopRound::dispatch($loop->id)->delay($loop->next_run_at);
    }

    public function markStarted(int $groupId): void
    {
        MessageGroup::query()
            ->whereKey($groupId)
            ->where('status', GroupStatus::Pending)
            ->update(['status' => GroupStatus::Sending, 'started_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Stops a send: pending rows become canceled; a row being sent right now still
     * finishes. Canceling a round of a repeating send stops the repeating too.
     */
    public function cancel(MessageGroup $group, ?int $userId = null): int
    {
        $canceled = DB::transaction(function () use ($group): int {
            $count = $group->messages()->where('status', MessageStatus::Pending)
                ->update(['status' => MessageStatus::Canceled, 'updated_at' => now()]);
            $group->update(['status' => GroupStatus::Canceled, 'finished_at' => now()]);

            if ($group->send_loop_id !== null) {
                SendLoop::query()->whereKey($group->send_loop_id)->where('status', LoopStatus::Active)
                    ->update(['status' => LoopStatus::Stopped, 'stopped_at' => now(), 'stop_reason' => 'canceled', 'next_run_at' => null, 'updated_at' => now()]);
            }

            return $count;
        });

        $this->events->info(EventKind::Sending, 'Mass send canceled', ['canceled' => $canceled], $group->user_phone_id, $group->id, $userId);

        return $canceled;
    }

    /** Stops a repeating send: no more rounds, and the round running now is canceled. */
    public function stopLoop(SendLoop $loop, ?int $userId = null): void
    {
        SendLoop::query()->whereKey($loop->id)->where('status', LoopStatus::Active)
            ->update(['status' => LoopStatus::Stopped, 'stopped_at' => now(), 'stop_reason' => 'canceled', 'next_run_at' => null, 'updated_at' => now()]);

        MessageGroup::query()->where('send_loop_id', $loop->id)->active()->get()
            ->each(fn (MessageGroup $round) => $this->cancel($round, $userId));

        $this->events->info(EventKind::Sending, 'Repeating send stopped', ['loop_id' => $loop->id], $loop->user_phone_id, userId: $userId);
    }
}
