<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** A stored picture (user avatars). */
class Image extends Model
{
    protected $fillable = ['path'];

    public function imageable(): MorphTo
    {
        return $this->morphTo();
    }
}
