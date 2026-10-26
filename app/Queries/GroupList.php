<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\GroupStatus;
use App\Models\MessageGroup;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The list of sends a user may see, with filters and delivery counts.
 * Used by the history page, the dashboard and the API.
 */
final class GroupList
{
    public const STATUSES = ['active', 'pending', 'sending', 'completed', 'failed', 'canceled'];

    /**
     * @param  array{search?: ?string, status?: ?string, phone_id?: ?int, user_id?: ?int, from?: ?string, to?: ?string}  $filters
     */
    public function query(User $user, array $filters = []): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $status = $filters['status'] ?? null;

        return MessageGroup::query()
            ->visibleTo($user)
            ->with(['phone:id,user_id,phone,first_name,last_name,username', 'phone.user:id,name', 'creator:id,name', 'catalogs:id,title', 'attachment', 'loop'])
            ->withDeliveryCounts()
            ->when($status === 'active', fn (Builder $q) => $q->active())
            ->when($status !== null && $status !== 'active' && in_array($status, self::STATUSES, true), fn (Builder $q) => $q->where('status', GroupStatus::from($status)))
            ->when($filters['phone_id'] ?? null, fn (Builder $q, $id) => $q->where('user_phone_id', $id))
            ->when($filters['user_id'] ?? null, fn (Builder $q, $id) => $q->where(fn (Builder $w) => $w
                ->where('created_by', $id)->orWhereHas('phone', fn (Builder $p) => $p->where('user_id', $id))))
            ->when($filters['from'] ?? null, fn (Builder $q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, $to) => $q->whereDate('created_at', '<=', $to))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('message_text', 'like', "%{$search}%")
                ->orWhereHas('phone', fn (Builder $p) => $p->where('phone', 'like', "%{$search}%"))
                ->orWhereHas('phone.user', fn (Builder $u) => $u->where('name', 'like', "%{$search}%"))
                ->orWhereHas('catalogs', fn (Builder $c) => $c->where('title', 'like', "%{$search}%"))
                ->when(ctype_digit($search), fn (Builder $id) => $id->orWhere('message_groups.id', (int) $search))))
            ->latest('id');
    }

    /** @param array<string, mixed> $filters */
    public function paginate(User $user, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return $this->query($user, $filters)->paginate(min(max($perPage, 5), 100))->withQueryString();
    }
}
