<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InfinityMessage extends Model
{
    protected $fillable = [
        'message_group_id',
        'peer',
        'message_text',
        'last_sent',
        'interval',
        'sent_count',
        'status',
        'starts_at',
        'stopped_at',
        'attempts'
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'stopped_at' => 'datetime',
    ];

    // InfinityMessage → MessageGroup
    public function messageGroup()
    {
        return $this->belongsTo(MessageGroup::class);
    }

    // InfinityMessage → UserPhone
    public function userPhone()
    {
        return $this->belongsTo(UserPhone::class);
    }
}
