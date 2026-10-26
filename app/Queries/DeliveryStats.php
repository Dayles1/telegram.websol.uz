<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\MessageStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Message counts over time, by error, by user and by account.
 * Everything is scoped by optional user/phone filters and a period.
 * Pure aggregates on indexed columns; no models are loaded.
 */
final class DeliveryStats
{
    private ?int $userId = null;

    private ?int $phoneId = null;

    public function __construct(private CarbonInterface $from, private CarbonInterface $to) {}

    public static function between(CarbonInterface $from, CarbonInterface $to): self
    {
        return new self($from, $to);
    }

    /** Only sends from this user's accounts. */
    public function forUser(?int $userId): self
    {
        $this->userId = $userId;

        return $this;
    }

    public function forPhone(?int $phoneId): self
    {
        $this->phoneId = $phoneId;

        return $this;
    }

    /** @return array{sent: int, failed: int, pending: int, canceled: int, groups: int, accounts: int} */
    public function totals(): array
    {
        $row = $this->messages()
            ->selectRaw("SUM(tm.status = 'sent') AS sent, SUM(tm.status = 'failed') AS failed")
            ->selectRaw("SUM(tm.status IN ('pending','sending')) AS pending, SUM(tm.status = 'canceled') AS canceled")
            ->selectRaw('COUNT(DISTINCT tm.message_group_id) AS `groups`, COUNT(DISTINCT mg.user_phone_id) AS accounts')
            ->first();

        return [
            'sent' => (int) $row->sent,
            'failed' => (int) $row->failed,
            'pending' => (int) $row->pending,
            'canceled' => (int) $row->canceled,
            'groups' => (int) $row->groups,
            'accounts' => (int) $row->accounts,
        ];
    }

    /**
     * Sent/failed per day (or per hour when the period is one day or less).
     *
     * @return list<array{bucket: string, sent: int, failed: int}>
     */
    public function series(): array
    {
        $hourly = $this->from->diffInHours($this->to) <= 48;
        $format = $hourly ? '%Y-%m-%d %H:00' : '%Y-%m-%d';

        $rows = $this->messages()
            ->whereIn('tm.status', [MessageStatus::Sent->value, MessageStatus::Failed->value])
            ->selectRaw("DATE_FORMAT(COALESCE(tm.sent_at, tm.updated_at), '{$format}') AS bucket")
            ->selectRaw("SUM(tm.status = 'sent') AS sent, SUM(tm.status = 'failed') AS failed")
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get()
            ->keyBy('bucket');

        $points = [];
        $cursor = $hourly ? $this->from->copy()->startOfHour() : $this->from->copy()->startOfDay();
        while ($cursor <= $this->to) {
            $key = $cursor->format($hourly ? 'Y-m-d H:00' : 'Y-m-d');
            $points[] = ['bucket' => $key, 'sent' => (int) ($rows[$key]->sent ?? 0), 'failed' => (int) ($rows[$key]->failed ?? 0)];
            $hourly ? $cursor->addHour() : $cursor->addDay();
        }

        return $points;
    }

    /** @return list<array{error_key: string, count: int}> */
    public function errors(int $limit = 12): array
    {
        // flood_wait_120 and flood_wait_30 are one reason; folded in PHP so it runs on MySQL 5.7 too.
        return $this->messages()
            ->where('tm.status', MessageStatus::Failed->value)
            ->selectRaw("COALESCE(tm.error_key, 'unknown_error') AS error_key, COUNT(*) AS count")
            ->groupBy('error_key')
            ->get()
            ->groupBy(fn ($r) => preg_replace('/_\d+$/', '', (string) $r->error_key))
            ->map(fn ($rows, string $key) => ['error_key' => $key, 'count' => (int) $rows->sum('count')])
            ->sortByDesc('count')
            ->take($limit)
            ->values()
            ->all();
    }

    /** @return list<array{user_id: int, name: string, groups: int, sent: int, failed: int}> */
    public function byUser(int $limit = 20): array
    {
        return $this->messages()
            ->join('users as u', 'u.id', '=', 'up.user_id')
            ->selectRaw("u.id AS user_id, u.name, COUNT(DISTINCT mg.id) AS `groups`, SUM(tm.status = 'sent') AS sent, SUM(tm.status = 'failed') AS failed")
            ->groupBy('u.id', 'u.name')
            ->orderByDesc('sent')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => ['user_id' => (int) $r->user_id, 'name' => (string) $r->name, 'groups' => (int) $r->groups, 'sent' => (int) $r->sent, 'failed' => (int) $r->failed])
            ->all();
    }

    /** @return list<array{phone_id: int, phone: string, owner: ?string, sent: int, failed: int, flood: int}> */
    public function byPhone(int $limit = 20): array
    {
        return $this->messages()
            ->leftJoin('users as u', 'u.id', '=', 'up.user_id')
            ->selectRaw("up.id AS phone_id, up.phone, u.name AS owner, SUM(tm.status = 'sent') AS sent, SUM(tm.status = 'failed') AS failed")
            ->selectRaw("SUM(tm.error_key LIKE 'flood_wait%' OR tm.error_key = 'peer_flood') AS flood")
            ->groupBy('up.id', 'up.phone', 'u.name')
            ->orderByDesc('sent')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => ['phone_id' => (int) $r->phone_id, 'phone' => (string) $r->phone, 'owner' => $r->owner, 'sent' => (int) $r->sent, 'failed' => (int) $r->failed, 'flood' => (int) $r->flood])
            ->all();
    }

    private function messages(): Builder
    {
        return DB::table('telegram_messages as tm')
            ->join('message_groups as mg', 'mg.id', '=', 'tm.message_group_id')
            ->join('user_phones as up', 'up.id', '=', 'mg.user_phone_id')
            ->whereBetween('tm.created_at', [$this->from, $this->to])
            ->when($this->userId, fn (Builder $q, int $id) => $q->where('up.user_id', $id))
            ->when($this->phoneId, fn (Builder $q, int $id) => $q->where('up.id', $id));
    }
}
