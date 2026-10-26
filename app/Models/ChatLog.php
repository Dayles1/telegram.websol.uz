<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ChatLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'chat_log_group_id',
        'message_id',
        'message_text',
        'caption',
        'message_date',
        'from_user_id',
        'from_username',
        'media_type',
        'media_file_name',
        'media_mime',
        'media_size',
        'media_local_path',
        'thumb_local_path',
        'provider_file_id',
    ];

    protected $casts = [
        'message_date' => 'datetime',
    ];

    /**
     * Har bir log qaysi groupga tegishli
     */
    public function group()
    {
        return $this->belongsTo(ChatLogGroup::class, 'chat_log_group_id');
    }
}
