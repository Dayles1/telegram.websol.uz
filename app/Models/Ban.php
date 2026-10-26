<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class Ban extends Model
{

    protected $fillable = [
        'bannable_type',
        'bannable_id',
        'reason',
        'active',
        'starts_at',
    ];

    protected $casts = [
        'active'    => 'boolean',
        'until'     => 'datetime',
        'starts_at' => 'datetime',
    ];

    public function bannable()
    {
        return $this->morphTo();
    }

    public function ensureActive()
    {
        if (!$this->active && $this->starts_at && $this->starts_at <= now()) {
            $this->active = true;
            $this->save();
        }
    }
    public function getStartsAtFormattedAttribute(): ?string
    {
        return $this->starts_at ? $this->starts_at->format('d-m-Y H:i:s') : null;
    }

    public function getUntilFormattedAttribute(): ?string
    {
        return $this->until ? $this->until->format('d-m-Y H:i:s') : null;
    }
    protected function startsAt(): Attribute
    {
        return Attribute::make(
            set: fn($value) => $value ? Carbon::parse($value)->setTimezone('Asia/Tashkent') : null,
        );
    }

    protected function until(): Attribute
    {
        return Attribute::make(
            set: fn($value) => $value ? Carbon::parse($value)->setTimezone('Asia/Tashkent') : null,
        );
    }
    public function isBanned(): bool
    {
        if (!$this->ban) {
            return false;
        }

        $this->ban->ensureActive();

        return $this->ban->active && $this->ban->starts_at && $this->ban->starts_at <= now();
    }
}
