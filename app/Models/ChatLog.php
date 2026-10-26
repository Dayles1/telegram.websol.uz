<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One version of one message of a followed chat. */
class ChatLog extends Model
{
    protected $fillable = [
        'chat_log_group_id', 'message_id', 'message_text', 'caption', 'message_date', 'from_user_id',
        'from_username', 'media_type', 'media_file_name', 'media_mime', 'media_size', 'media_local_path',
        'thumb_local_path', 'provider_file_id',
    ];

    protected function casts(): array
    {
        return ['message_date' => 'datetime'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ChatLogGroup::class, 'chat_log_group_id');
    }
}
