<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MessageStatus;
use App\Models\Casts\LenientEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message to one peer inside a group. The text lives on the group
 * (message_text here is only filled on rows written by the old code).
 *
 * @property MessageStatus $status
 */
class TelegramMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'message_group_id', 'telegram_message_id', 'peer', 'send_at', 'claimed_at', 'sent_at',
        'status', 'attempts', 'error_key',
    ];

    protected function casts(): array
    {
        return [
            'status' => LenientEnum::class.':'.MessageStatus::class.',failed',
            'send_at' => 'datetime',
            'claimed_at' => 'datetime',
            'sent_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(MessageGroup::class, 'message_group_id');
    }
}
