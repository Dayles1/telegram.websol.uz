<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramMessage extends Model
{
    protected $fillable = [
        'message_group_id',
        'telegram_message_id',//no need
        'peer',
        'message_text',//no need
        'send_at',
        'sent_at',
        'status',
        'attempts',
        'error_key'
    ];

    protected $casts = [
        'send_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function group()
    {
        return $this->belongsTo(MessageGroup::class, 'message_group_id');
    }
    
}
    

