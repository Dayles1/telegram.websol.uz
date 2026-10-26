<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TelegramMessage;
use App\Support\ErrorText;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TelegramMessage */
final class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $peer = (string) $this->peer;

        return [
            'id' => $this->id,
            'peer' => $peer,
            'link' => self::link($peer),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'tone' => $this->status->tone(),
            'error_key' => $this->error_key,
            'error_text' => ErrorText::for($this->error_key),
            'attempts' => $this->attempts,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    public static function link(string $peer): ?string
    {
        return match (true) {
            str_starts_with($peer, '@') => 'https://t.me/'.substr($peer, 1),
            str_contains($peer, 't.me/') => str_starts_with($peer, 'http') ? $peer : 'https://'.$peer,
            default => null,
        };
    }
}
