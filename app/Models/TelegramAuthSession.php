<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// app/Models/TelegramAuthSession.php
class TelegramAuthSession extends Model {
    protected $fillable = ['user_id','phone','status','message','message_key','telegram_user_id','session_path','attempts','last_ping'];

}

