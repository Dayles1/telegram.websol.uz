<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\MessageGroup;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Numbers for the external dashboard (behind the "stats" middleware now).
 * Same response shapes as before; every value goes through bindings.
 */
final class StatisticsController extends Controller
{
    private const PERIODS = ['day', 'week', 'month', 'year', 'all'];

    private const STATUS_TEXT = ['sent' => 'Yuborilgan', 'failed' => 'Yuborilmagan', 'pending' => 'Kutilayotgan', 'canceled' => 'Bekor qilingan'];

    /** GET /api/stats/top-users?period=&type=top|bottom|both&limit= */
    public function topUsers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period' => ['nullable', Rule::in(self::PERIODS)],
            'type' => ['nullable', Rule::in(['top', 'bottom', 'both'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $period = $data['period'] ?? 'all';
        $type = $data['type'] ?? 'both';
        $limit = (int) ($data['limit'] ?? 5);
        $from = $this->periodStart($period);

        $rows = fn (bool $top) => $this->usersWithCounts($from)
            ->when(! $top, fn (Builder $q) => $q->having('operations_count', '>', 0))
            ->orderBy('operations_count', $top ? 'desc' : 'asc')
            ->orderBy('messages_count', $top ? 'desc' : 'asc')
            ->limit($limit)
            ->get();

        $base = ['generated_at' => now()->toDateTimeString(), 'period' => $period, 'type' => $type, 'limit' => $limit];

        return response()->json($type === 'both'
            ? $base + ['top' => $rows(true), 'bottom' => $rows(false)]
            : $base + ['data' => $rows($type === 'top')]);
    }

    /** GET /api/stats/top-users-by-status?status=&limit=&period= */
    public function topUsersByStatus(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(array_keys(self::STATUS_TEXT))],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'period' => ['nullable', Rule::in(self::PERIODS)],
        ]);
        $status = $data['status'] ?? 'failed';
        $limit = (int) ($data['limit'] ?? 5);
        $period = $data['period'] ?? 'all';
        $from = $this->periodStart($period);

        $users = $this->nonAdmins()
            ->leftJoin('user_phones as up', 'up.user_id', '=', 'users.id')
            ->leftJoin('message_groups as mg', 'mg.user_phone_id', '=', 'up.id')
            ->leftJoin('telegram_messages as tm', fn ($j) => $j->on('tm.message_group_id', '=', 'mg.id')
                ->where('tm.status', '=', $status)
                ->when($from, fn ($jj) => $jj->where('tm.created_at', '>=', $from)))
            ->groupBy('users.id', 'users.name', 'users.email')
            ->selectRaw('users.id, users.name, users.email, COUNT(tm.id) AS messages_count')
            ->orderByDesc('messages_count')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'name' => $r->name ?? '—',
                'email' => $r->email,
                'status' => $status,
                'messages_count' => (int) $r->messages_count,
                'messages_text' => number_format((int) $r->messages_count).' ta '.self::STATUS_TEXT[$status].' habar',
            ]);

        return response()->json([
            'generated_at' => now()->toDateTimeString(),
            'status' => $status,
            'limit' => $limit,
            'period' => $period,
            'top' => $users,
        ]);
    }

    /** GET /api/stats/dashboard-realtime?per_page= */
    public function realtime(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 10), 1), 50);

        $groups = MessageGroup::query()
            ->with('phone.user:id,name')
            ->withDeliveryCounts()
            ->withMin('messages', 'created_at')
            ->withMax('messages', 'sent_at')
            ->latest('id')
            ->limit($perPage)
            ->get()
            ->map(fn (MessageGroup $g) => [
                'id' => $g->id,
                'creator' => $g->phone?->user ? ['id' => $g->phone->user->id, 'name' => $g->phone->user->name] : null,
                'messages_count' => (int) $g->messages_count,
                'sent_count' => (int) $g->sent_count,
                'percent' => $g->messages_count > 0 ? (int) round($g->sent_count / $g->messages_count * 100) : 0,
                'status' => $g->status->value,
                'started_at' => $g->messages_min_created_at ? Carbon::parse($g->messages_min_created_at)->toDateTimeString() : null,
                'finished_at' => $g->messages_max_sent_at ? Carbon::parse($g->messages_max_sent_at)->toDateTimeString() : null,
                'text_preview' => mb_substr((string) ($g->message_text ?? '-'), 0, 120),
            ]);

        return response()->json(['fetched_at' => now()->toDateTimeString(), 'count' => $groups->count(), 'groups' => $groups]);
    }

    /** Users (not superadmins) with operations_count (sends) and messages_count (delivered) since $from. */
    private function usersWithCounts(?CarbonInterface $from): Builder
    {
        $operations = DB::table('message_groups as mg')
            ->join('user_phones as up', 'up.id', '=', 'mg.user_phone_id')
            ->when($from, fn ($q) => $q->where('mg.created_at', '>=', $from))
            ->groupBy('up.user_id')
            ->selectRaw('up.user_id, COUNT(*) AS operations');

        $messages = DB::table('telegram_messages as tm')
            ->join('message_groups as mg', 'mg.id', '=', 'tm.message_group_id')
            ->join('user_phones as up', 'up.id', '=', 'mg.user_phone_id')
            ->where('tm.status', 'sent')
            ->when($from, fn ($q) => $q->where('tm.created_at', '>=', $from))
            ->groupBy('up.user_id')
            ->selectRaw('up.user_id, COUNT(*) AS messages');

        return $this->nonAdmins()
            ->leftJoinSub($operations, 'ops', 'ops.user_id', '=', 'users.id')
            ->leftJoinSub($messages, 'msgs', 'msgs.user_id', '=', 'users.id')
            ->selectRaw('users.id, users.name, users.email, COALESCE(ops.operations, 0) AS operations_count, COALESCE(msgs.messages, 0) AS messages_count');
    }

    private function nonAdmins(): Builder
    {
        return DB::table('users')
            ->leftJoin('roles', 'roles.id', '=', 'users.role_id')
            ->where(fn ($q) => $q->whereNull('roles.name')->orWhere('roles.name', '!=', Role::Superadmin->value));
    }

    private function periodStart(string $period): ?CarbonInterface
    {
        return match ($period) {
            'day' => now()->startOfDay(),
            'week' => now()->startOfWeek(),
            'month' => now()->startOfMonth(),
            'year' => now()->startOfYear(),
            default => null,
        };
    }
}
