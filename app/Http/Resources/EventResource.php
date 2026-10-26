<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SystemEvent;
use App\Support\ErrorText;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SystemEvent */
final class EventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'level' => $this->level->value,
            'level_label' => $this->level->label(),
            'tone' => $this->level->tone(),
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'message' => $this->message,
            'error_key' => $this->error_key,
            'error_text' => ErrorText::for($this->error_key),
            'context' => $this->context,
            'occurrences' => $this->occurrences,
            'phone' => $this->whenLoaded('phone', fn () => $this->phone ? ['id' => $this->phone->id, 'phone' => $this->phone->phone] : null),
            'user' => $this->whenLoaded('user', fn () => $this->user?->name),
            'group_id' => $this->message_group_id,
            'group_url' => $this->message_group_id ? route('sends.show', $this->message_group_id) : null,
            'first_seen_at' => $this->first_seen_at->toIso8601String(),
            'last_seen_at' => $this->last_seen_at->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'notified_at' => $this->notified_at?->toIso8601String(),
            'url' => route('monitoring.events.show', $this->id),
        ];
    }
}
