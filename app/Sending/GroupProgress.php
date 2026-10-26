<?php

declare(strict_types=1);

namespace App\Sending;

use App\Enums\EventKind;
use App\Enums\GroupStatus;
use App\Enums\LoopStatus;
use App\Enums\MessageStatus;
use App\Enums\TaskStatus;
use App\Jobs\StartLoopRound;
use App\Models\MessageGroup;
use App\Models\MessageTask;
use App\Models\SendLoop;
use App\Models\TelegramMessage;
use App\Models\User;
use App\Monitoring\EventRecorder;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * "Stop everything": every active send the user may see, the repeating sends behind
     * them and pending API messages (only one account's, when given). Rows being sent
     * right now still finish.
     *
     * @return array{groups: int, messages: int, loops: int, tasks: int}
     */
    public function cancelAll(User $user, ?int $phoneId = null): array
    {
        $loops = SendLoop::query()->visibleTo($user)->where('status', LoopStatus::Active)
            ->when($phoneId, fn (Builder $q, int $id) => $q->where('user_phone_id', $id))
            ->pluck('id');

        $groups = MessageGroup::query()->visibleTo($user)->active()
            ->when($phoneId, fn (Builder $q, int $id) => $q->where('user_phone_id', $id))
            ->get();
        $messages = $groups->sum(fn (MessageGroup $group) => $this->cancel($group, $user->id));

        // repeating sends waiting for their next round have no active group to cancel
        SendLoop::query()->whereIn('id', $loops)->where('status', LoopStatus::Active)->get()
            ->each(fn (SendLoop $loop) => $this->stopLoop($loop, $user->id));

        // MessageTask has no "canceled": a failed task with this code is what API clients understand
        $tasks = MessageTask::query()->where('status', TaskStatus::Pending)
            ->when(! $user->isSuperadmin(), fn (Builder $q) => $q->whereHas('sender', fn (Builder $p) => $p->where('user_id', $user->id)))
            ->when($phoneId, fn (Builder $q, int $id) => $q->where('sender_phone_id', $id))
            ->update(['status' => TaskStatus::Failed, 'error_code' => 'CANCELED', 'error_message' => 'Canceled by the user', 'updated_at' => now()]);

        $result = ['groups' => $groups->count(), 'messages' => (int) $messages, 'loops' => $loops->count(), 'tasks' => $tasks];
        $this->events->info(EventKind::Sending, 'All sends stopped', $result + ['phone_id' => $phoneId], $phoneId, userId: $user->id);

        return $result;
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
