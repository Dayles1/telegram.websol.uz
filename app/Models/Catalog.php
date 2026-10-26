<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Catalog extends Model
{
    protected $fillable = [
        'title',
        'user_id',
        'peers',
        'is_global',
        'timer_minutes',
    ];

    protected $casts = [
        'peers' => 'array',
        'is_global' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function messageGroups()
    {
        return $this->belongsToMany(MessageGroup::class, 'catalog_message_group');
    }
    public function phone()
    {
        return $this->belongsTo(UserPhone::class);
    }
}

