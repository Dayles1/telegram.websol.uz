<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named list of peers (usernames, t.me links, ids) a user sends to.
 * A global catalog is usable by everybody; timer_minutes is a cooldown per account.
 */
class Catalog extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'user_id', 'peers', 'is_global', 'timer_minutes'];

    protected function casts(): array
    {
        return [
            'peers' => 'array',
            'is_global' => 'boolean',
            'timer_minutes' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messageGroups(): BelongsToMany
    {
        return $this->belongsToMany(MessageGroup::class, 'catalog_message_group');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(CatalogUsage::class);
    }

    /** Catalogs the user may send to: their own, the global ones, all for a superadmin. */
    public function scopeUsableBy(Builder $query, User $user): void
    {
        if (! $user->isSuperadmin()) {
            $query->where(fn (Builder $q) => $q->where('user_id', $user->id)->orWhere('is_global', true));
        }
    }

    /** @return list<string> */
    public function peerList(): array
    {
        return array_values(array_filter(array_map('strval', $this->peers ?? []), fn (string $p) => trim($p) !== ''));
    }
}
