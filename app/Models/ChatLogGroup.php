<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A chat whose history is copied into chat_logs (API: fetch-messages / chat-logs). */
class ChatLogGroup extends Model
{
    protected $fillable = ['user_phone_id', 'peer', 'status', 'chat_type', 'last_fetch_at'];

    protected function casts(): array
    {
        return ['last_fetch_at' => 'datetime'];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ChatLog::class, 'chat_log_group_id');
    }

    public function phone(): BelongsTo
    {
        return $this->belongsTo(UserPhone::class, 'user_phone_id');
    }
}
