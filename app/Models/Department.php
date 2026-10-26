<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends Model
{
    // use SoftDeletes;
    // use LogsActivity;

    protected $fillable = [
        'name',
        'plan',
        'type',
    ];
public function isBanned(): bool
    {
        if (!$this->ban) {
            return false;
        }

        $this->ban->ensureActive();

        return $this->ban->active && $this->ban->starts_at && $this->ban->starts_at <= now();
    }
    public function users()
    {
        return $this->hasMany(User::class);
    }
    public function ban()
    {
        return $this->morphOne(\App\Models\Ban::class, 'bannable');
    }
    public function activeBan()
    {
        return $this->morphOne(\App\Models\Ban::class, 'bannable')->where('active', true);
    }

    public function isActiveBanned(): bool
    {
        return $this->activeBan()->exists();
    }
    public function limit()
    {
        return $this->morphOne(Limit::class, 'limitable');
    }
    // public function getActivitylogOptions(): LogOptions
    // {
    //     return LogOptions::defaults()
    //         ->useLogName('department')
    //         ->logFillable()
    //         ->logOnlyDirty()
    //         ->dontSubmitEmptyLogs(); 
    // }
    public function isTrial(): bool
    {
        return $this->plan === 'trial';
    }

    public function isTrialActive(): bool
    {
        if (!$this->trial_expires_at) {
            return false;
        }

        return now()->lessThanOrEqualTo($this->trial_expires_at);
    }

    public function isPro(): bool
    {
        return $this->plan === 'pro';
    }
}
