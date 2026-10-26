<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TaskStatus;
use App\Models\Casts\LenientEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single message to one person ordered through the API, by Telegram id
 * and/or phone number. Sent by the same per-account worker as mass sends.
 *
 * @property TaskStatus $status
 */
class MessageTask extends Model
{
    use HasFactory;

    protected $table = 'telegram_message_tasks';

    protected $fillable = [
        'sender_phone_id', 'target_tg_id', 'target_phone', 'message_text', 'status',
        'error_code', 'error_message', 'meta', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => LenientEnum::class.':'.TaskStatus::class.',failed',
            'meta' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(UserPhone::class, 'sender_phone_id');
    }
}
