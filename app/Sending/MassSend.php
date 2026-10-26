<?php

declare(strict_types=1);

namespace App\Sending;

use App\Enums\EventKind;
use App\Enums\GroupStatus;
use App\Enums\LoopStatus;
use App\Enums\MessageStatus;
use App\Exceptions\ActionRefused;
use App\Jobs\SendAccountMessages;
use App\Models\Attachment;
use App\Models\Catalog;
use App\Models\MessageGroup;
use App\Models\SendLoop;
use App\Models\TelegramMessage;
use App\Models\User;
use App\Models\UserPhone;
use App\Monitoring\EventRecorder;
use App\Telegram\Phone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Creates sends: one group, one pending row per unique peer, then puts the
 * account's send job on the queue. Nothing is sent here.
 *
 * A repeating send (SendLoop) gets its first round now; every next round is
 * started by startRound() once the previous one finished (GroupProgress
 * schedules it on the queue).
 */
final class MassSend
{
    public const MAX_TEXT_LENGTH = 4096;

    /** Longer texts are sent as a second message after the file. */
    public const MAX_CAPTION_LENGTH = 1024;

    public const MAX_REPEAT_MINUTES = 60 * 24 * 30;

    public const MAX_ROUNDS = 100000;

    public function __construct(
        private readonly CatalogCooldown $cooldown,
        private readonly Attachments $attachments,
        private readonly EventRecorder $events,
    ) {}

    public function create(User $actor, UserPhone $phone, SendOrder $order): MessageGroup
    {
        if (! $phone->canSend()) {
            throw new ActionRefused('phone_not_ready', status: 422);
        }
        if (trim($order->text) === '' && $order->file === null) {
            throw new ActionRefused('nothing_to_send', status: 422);
        }

        $catalogIds = array_values(array_unique($order->catalogIds));
        $catalogs = Catalog::query()->usableBy($actor)->whereIn('id', $catalogIds)->get();
        if ($catalogs->count() !== count($catalogIds)) {
            throw new ActionRefused('catalog_not_allowed', status: 403);
        }

        $peers = $this->peersOf($catalogs, $order->peers);
        if ($peers === []) {
            throw new ActionRefused('no_peers', status: 422);
        }

        $attachment = $order->file !== null ? $this->attachments->store($order->file, $actor) : null;

        try {
            $group = DB::transaction(function () use ($actor, $phone, $catalogs, $peers, $order, $attachment): MessageGroup {
                $this->cooldown->reserve($catalogs, $phone->id);

                $loop = $order->repeats() ? SendLoop::query()->create([
                    'user_phone_id' => $phone->id,
                    'created_by' => $actor->id,
                    'status' => LoopStatus::Active,
                    'message_text' => $order->text,
                    'attachment_id' => $attachment?->id,
                    'catalog_ids' => $catalogs->modelKeys() ?: null,
                    'peers' => $order->peers !== [] ? Peers::unique($order->peers) : null,
                    'every_minutes' => min($order->repeatEveryMinutes, self::MAX_REPEAT_MINUTES),
                    'max_rounds' => $order->maxRounds,
                    'rounds_done' => 1,
                ]) : null;

                return $this->createGroup($actor->id, $phone->id, $order->text, $attachment, $loop, $catalogs, $peers);
            });
        } catch (Throwable $e) {
            if ($attachment !== null) {
                $this->attachments->discard($attachment);
            }
            throw $e;
        }

        SendAccountMessages::dispatch($phone->id);

        $this->events->info(EventKind::Sending, $group->send_loop_id ? 'Repeating send created' : 'Mass send created', array_filter([
            'peers' => count($peers),
            'catalogs' => $catalogs->pluck('title')->all(),
            'file' => $attachment?->original_name,
            'every_minutes' => $order->repeats() ? $order->repeatEveryMinutes : null,
            'phone' => Phone::mask($phone->phone),
        ]), $phone->id, $group->id, $actor->id);

        return $group;
    }

    /** A new send to the peers of $group that failed, from the same account, text and file. */
    public function retryFailed(User $actor, MessageGroup $group): MessageGroup
    {
        $peers = $group->messages()->where('status', MessageStatus::Failed)->pluck('peer')->filter()->unique()->values()->all();
        if ($peers === []) {
            throw new ActionRefused('nothing_to_retry', status: 422);
        }
        if (! $group->phone?->canSend()) {
            throw new ActionRefused('phone_not_ready', status: 422);
        }
        if ($group->attachment_id !== null && ($group->attachment === null || $group->attachment->isRemoved())) {
            throw new ActionRefused('attachment_removed', status: 422);
        }

        $retry = DB::transaction(fn (): MessageGroup => $this->createGroup(
            $actor->id,
            (int) $group->user_phone_id,
            (string) $group->message_text,
            $group->attachment,
            null,
            $group->catalogs()->get(),
            $peers,
        ));

        SendAccountMessages::dispatch((int) $group->user_phone_id);
        $this->events->info(EventKind::Sending, 'Failed messages sent again', ['from_group' => $group->id, 'peers' => count($peers)], $group->user_phone_id, $retry->id, $actor->id);

        return $retry;
    }

    /**
     * The next round of a repeating send, when it is due. Safe to call any time
     * and more than once (the queued job and the minute tick both do): it starts a
     * round only when the loop is active, due, and has no round running.
     */
    public function startRound(int $loopId): ?MessageGroup
    {
        $stopReason = null;

        $group = DB::transaction(function () use ($loopId, &$stopReason): ?MessageGroup {
            $loop = SendLoop::query()->with(['phone', 'attachment', 'creator'])->lockForUpdate()->find($loopId);
            if ($loop === null || ! $loop->isActive() || $loop->next_run_at === null || $loop->next_run_at->isFuture()) {
                return null;
            }
            if (MessageGroup::query()->where('send_loop_id', $loop->id)->active()->exists()) {
                return null;
            }

            $phone = $loop->phone;
            $actor = $loop->creator ?? $phone?->user;
            $catalogs = $actor !== null
                ? Catalog::query()->usableBy($actor)->whereIn('id', $loop->catalog_ids ?? [])->get()
                : new Collection;
            $peers = $this->peersOf($catalogs, $loop->peers ?? []);

            $stopReason = match (true) {
                $phone === null || ! $phone->canSend() => 'phone_not_ready',
                $loop->attachment_id !== null && ($loop->attachment === null || $loop->attachment->isRemoved()) => 'attachment_removed',
                $peers === [] => 'no_peers',
                default => null,
            };
            if ($stopReason !== null) {
                $loop->update(['status' => LoopStatus::Stopped, 'stopped_at' => now(), 'stop_reason' => $stopReason, 'next_run_at' => null]);

                return null;
            }

            $this->cooldown->markUsed($catalogs, $phone->id);
            $loop->update(['rounds_done' => $loop->rounds_done + 1, 'next_run_at' => null]);

            return $this->createGroup($loop->created_by, $phone->id, (string) $loop->message_text, $loop->attachment, $loop, $catalogs, $peers);
        });

        if ($stopReason !== null) {
            $this->events->warning(EventKind::Sending, 'Repeating send stopped: the next round can not start', [
                'loop_id' => $loopId,
                'reason' => $stopReason,
            ], errorKey: 'loop_stopped');
        }
        if ($group !== null) {
            SendAccountMessages::dispatch((int) $group->user_phone_id);
            $this->events->info(EventKind::Sending, 'Repeating send started a new round', ['loop_id' => $loopId], $group->user_phone_id, $group->id);
        }

        return $group;
    }

    /**
     * @param  Collection<int, Catalog>  $catalogs
     * @param  list<string>  $peers
     * @return list<string>
     */
    private function peersOf(Collection $catalogs, array $peers): array
    {
        return Peers::unique([...$catalogs->flatMap(fn (Catalog $c) => $c->peerList())->all(), ...$peers]);
    }

    /**
     * @param  Collection<int, Catalog>  $catalogs
     * @param  list<string>  $peers
     */
    private function createGroup(?int $actorId, int $phoneId, string $text, ?Attachment $attachment, ?SendLoop $loop, Collection $catalogs, array $peers): MessageGroup
    {
        $group = MessageGroup::query()->create([
            'user_phone_id' => $phoneId,
            'created_by' => $actorId,
            'status' => GroupStatus::Pending,
            'message_text' => $text,
            'attachment_id' => $attachment?->id,
            'send_loop_id' => $loop?->id,
        ]);
        $group->catalogs()->sync($catalogs->modelKeys());

        $now = now();
        foreach (array_chunk($peers, 1000) as $chunk) {
            TelegramMessage::query()->insert(array_map(fn (string $peer) => [
                'message_group_id' => $group->id,
                'peer' => $peer,
                'status' => MessageStatus::Pending->value,
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }

        return $group;
    }
}
