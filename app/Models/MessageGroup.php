<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GroupStatus;
use App\Enums\MessageStatus;
use App\Models\Casts\LenientEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One mass send: a text and/or a file, an account, and one telegram_messages row
 * per peer. A round of a repeating send has send_loop_id.
 *
 * @property GroupStatus $status
 */
class MessageGroup extends Model
{
    use HasFactory;

    protected $fillable = ['user_phone_id', 'created_by', 'status', 'message_text', 'attachment_id', 'send_loop_id', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return [
            'status' => LenientEnum::class.':'.GroupStatus::class.',failed',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
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

    public function messages(): HasMany
    {
        return $this->hasMany(TelegramMessage::class);
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }

    public function loop(): BelongsTo
    {
        return $this->belongsTo(SendLoop::class, 'send_loop_id');
    }

    public function catalogs(): BelongsToMany
    {
        return $this->belongsToMany(Catalog::class, 'catalog_message_group')->withTimestamps();
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->isSuperadmin()) {
            $query->where(fn (Builder $q) => $q
                ->whereHas('phone', fn (Builder $p) => $user->limitToOverseen($p))
                ->orWhere('created_by', $user->id));
        }
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', GroupStatus::activeValues());
    }

    /** Adds sent/failed/canceled counts, pending_count (pending + sending: not finished), sending_count, messages_count. */
    public function scopeWithDeliveryCounts(Builder $query): void
    {
        $query->withCount([
            'messages',
            'messages as sent_count' => fn (Builder $q) => $q->where('status', MessageStatus::Sent),
            'messages as failed_count' => fn (Builder $q) => $q->where('status', MessageStatus::Failed),
            'messages as pending_count' => fn (Builder $q) => $q->whereIn('status', [MessageStatus::Pending, MessageStatus::Sending]),
            'messages as sending_count' => fn (Builder $q) => $q->where('status', MessageStatus::Sending),
            'messages as canceled_count' => fn (Builder $q) => $q->where('status', MessageStatus::Canceled),
        ]);
    }

    public function progressPercent(): int
    {
        $total = (int) ($this->messages_count ?? 0);
        if ($total === 0) {
            return 0;
        }
        $done = (int) $this->sent_count + (int) $this->failed_count + (int) $this->canceled_count;

        return (int) floor($done / $total * 100);
    }
}
