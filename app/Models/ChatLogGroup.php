<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ChatLogGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_phone_id',
        'peer',
        'status',
        'chat_type',
        'last_fetch_at',
    ];
    protected $casts = [
        'last_fetch_at' => 'datetime',
    ];

    /**
     * Bir groupga tegishli barcha chat loglar
     */
    public function logs()
    {
        return $this->hasMany(ChatLog::class, 'chat_log_group_id');
    }

    /**
     * Groupga tegishli phone
     */
    public function phone()
    {
        return $this->belongsTo(UserPhone::class, 'user_phone_id');
    }
}
