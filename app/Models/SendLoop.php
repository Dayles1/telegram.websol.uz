<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LoopStatus;
use App\Models\Casts\LenientEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A repeating send. Each round is a message group; the next round starts
 * every_minutes after the previous one finished (App\Sending\SendLoops).
 *
 * @property LoopStatus $status
 * @property list<int>|null $catalog_ids
 * @property list<string>|null $peers
 */
class SendLoop extends Model
{
    protected $fillable = [
        'user_phone_id', 'created_by', 'status', 'message_text', 'attachment_id', 'catalog_ids', 'peers',
        'every_minutes', 'max_rounds', 'rounds_done', 'next_run_at', 'stopped_at', 'stop_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => LenientEnum::class.':'.LoopStatus::class.',stopped',
            'catalog_ids' => 'array',
            'peers' => 'array',
            'every_minutes' => 'integer',
            'max_rounds' => 'integer',
            'rounds_done' => 'integer',
            'next_run_at' => 'datetime',
            'stopped_at' => 'datetime',
        ];
    }

    public function phone(): BelongsTo
    {
        return $this->belongsTo(UserPhone::class, 'user_phone_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }

    public function rounds(): HasMany
    {
        return $this->hasMany(MessageGroup::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->isSuperadmin()) {
            $query->where(fn (Builder $q) => $q
                ->whereHas('phone', fn (Builder $p) => $user->limitToOverseen($p))
                ->orWhere('created_by', $user->id));
        }
    }

    public function isActive(): bool
    {
        return $this->status === LoopStatus::Active;
    }
}
