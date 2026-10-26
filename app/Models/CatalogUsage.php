<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogUsage extends Model
{
    protected $fillable = ['catalog_id', 'user_phone_id', 'last_used_at'];
    protected $dates = ['last_used_at'];

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(Catalog::class);
    }
}
