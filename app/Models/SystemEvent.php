<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EventKind;
use App\Enums\EventLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property EventLevel $level
 * @property EventKind $kind
 */
class SystemEvent extends Model
{
    protected $fillable = [
        'level', 'kind', 'message', 'error_key', 'user_id', 'user_phone_id', 'message_group_id',
        'context', 'fingerprint', 'occurrences', 'first_seen_at', 'last_seen_at', 'resolved_at', 'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'level' => EventLevel::class,
            'kind' => EventKind::class,
            'context' => 'array',
            'occurrences' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function phone(): BelongsTo
    {
        return $this->belongsTo(UserPhone::class, 'user_phone_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(MessageGroup::class, 'message_group_id');
    }

    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }

    public function scopeProblems(Builder $query): void
    {
        $query->whereIn('level', [EventLevel::Warning, EventLevel::Error, EventLevel::Critical]);
    }
}
