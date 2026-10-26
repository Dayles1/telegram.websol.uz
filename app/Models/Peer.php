<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Peer extends Model
{
    protected $fillable = [
        'peer',
        'peer_id',
        'peer_type',
        'title',
        'username',
        'last_resolved_at',
        'usage_count',
    ];

    protected $casts = [
        'last_resolved_at' => 'datetime',
    ];
}