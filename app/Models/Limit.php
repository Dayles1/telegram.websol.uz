<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Limit extends Model
{
    protected $table = 'limits';

     protected $fillable = [
        'max_users',
        'limitable_type',
        'limitable_id',
        // 'max_phones',
        // 'max_operations',
    ];

    /**
     * Polymorphic relation
     * Kimga tegishli ekanini bildiradi (User, Admin, Department va hokazo)
     */
    public function limitable()
    {
        return $this->morphTo();
    }
}
