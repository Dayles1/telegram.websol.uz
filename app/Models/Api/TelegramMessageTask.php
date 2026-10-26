<?php

namespace App\Models\Api;

use Illuminate\Database\Eloquent\Model;

class TelegramMessageTask extends Model
{
    protected $fillable = [
        'sender_phone_id',
        'target_tg_id',
        'target_phone',
        'message_text',
        'status',
        'error_code',
        'error_message',
        'meta',
        'sent_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'sent_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_TARGET_NOT_FOUND = 'target_not_found';
    public const STATUS_TARGET_HIDDEN = 'target_hidden';
    public const STATUS_SENDER_NOT_LOGGED_IN = 'sender_not_logged_in';
    public const STATUS_START_FAILED = 'start_failed';
    public const STATUS_PEER_FLOOD = 'peer_flood';
}