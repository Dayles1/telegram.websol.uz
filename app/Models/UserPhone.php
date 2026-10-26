<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPhone extends Model
{
    protected $fillable = [
        'user_id',
        'phone',
        'telegram_user_id',
        'session_path',
        'session_delete_at'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function messageGroups()
    {
        return $this->hasMany(MessageGroup::class, 'user_phone_id');
    }

    public function catalogs()
    {
        return $this->hasMany(Catalog::class);
    }

    public function chatLogGroups()
    {
        return $this->hasMany(ChatLogGroup::class, 'user_phone_id');
    }
    public function usages()
    {
        return $this->hasMany(CatalogUsage::class);
    }

    public function usageForPhone($phoneId)
    {
        return $this->hasOne(CatalogUsage::class)->where('user_phone_id', $phoneId);
    }
    
}
