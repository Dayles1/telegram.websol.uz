<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\GroupStatus;
use App\Models\MessageGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A send as the panel shows it. Needs withDeliveryCounts() on the query.
 *
 * @mixin MessageGroup
 */
final class GroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'tone' => $this->status->tone(),
            'active' => $this->status->isActive(),
            'text' => (string) $this->message_text,
            'phone' => $this->whenLoaded('phone', fn () => $this->phone ? [
                'id' => $this->phone->id,
                'phone' => $this->phone->phone,
                'name' => $this->phone->display_name,
                'owner' => $this->phone->relationLoaded('user') ? $this->phone->user?->name : null,
            ] : null),
            'creator' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'catalogs' => $this->whenLoaded('catalogs', fn () => $this->catalogs->map(fn ($c) => ['id' => $c->id, 'title' => $c->title])->all()),
            'file' => $this->whenLoaded('attachment', fn () => $this->attachment?->summary()),
            'loop' => $this->whenLoaded('loop', fn () => $this->loop ? [
                'id' => $this->loop->id,
                'status' => $this->loop->status->value,
                'status_label' => $this->loop->status->label(),
                'tone' => $this->loop->status->tone(),
                'active' => $this->loop->isActive(),
                'every_minutes' => $this->loop->every_minutes,
                'rounds_done' => $this->loop->rounds_done,
                'max_rounds' => $this->loop->max_rounds,
                'next_run_at' => $this->loop->next_run_at?->toIso8601String(),
                'stop_reason' => $this->loop->stop_reason,
                'stop_url' => route('sends.loops.stop', $this->loop->id),
            ] : null),
            'counts' => [
                'total' => (int) $this->messages_count,
                'sent' => (int) $this->sent_count,
                'failed' => (int) $this->failed_count,
                'pending' => (int) $this->pending_count, // not finished yet: pending + sending
                'sending' => (int) $this->sending_count,
                'canceled' => (int) $this->canceled_count,
            ],
            'progress' => $this->progressPercent(),
            'created_at' => $this->created_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'url' => route('sends.show', $this->id),
            'can' => [
                'cancel' => $this->status->isActive(),
                'retry' => ! $this->status->isActive() && $this->status !== GroupStatus::LegacyInfinity && (int) $this->failed_count > 0
                    && ! ($this->relationLoaded('attachment') && $this->attachment?->isRemoved()),
                'stop_loop' => $this->relationLoaded('loop') && $this->loop?->isActive() === true,
            ],
        ];
    }
}
