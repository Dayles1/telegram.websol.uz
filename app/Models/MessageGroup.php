<?php

namespace App\Models;

use App\Models\Peer;
use App\Models\UserPhone;
use App\Models\TelegramMessage;
use Illuminate\Database\Eloquent\Model;

class MessageGroup extends Model
{
    protected $fillable = [
        'user_phone_id',
        'status',
        'message_text'
    ];
    protected $casts = [
        'last_sent_at' => 'datetime',
    ];


    public function phone()
    {
        return $this->belongsTo(UserPhone::class, 'user_phone_id');
    }

    public function infinityMessages()
    {
        return $this->hasMany(InfinityMessage::class);
    }

    public function messages()
    {
        return $this->hasMany(TelegramMessage::class);
    }
    public function catalogs()
    {
        return $this->belongsToMany(Catalog::class, 'catalog_message_group');
    }
}
