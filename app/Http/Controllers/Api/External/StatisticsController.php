<?php

namespace App\Http\Controllers\Api\External;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use App\Models\MessageGroup;

class StatisticsController extends Controller
{
    /**
     * Top 5 va Bottom 5 users (superadminlarni hisobga olmasdan).
     * Bu endpointni 10 daqiqagacha cache qilishni tavsiya qilamiz.
     */
    public function usersStats(Request $request)
{
    $period = strtolower($request->input('period', 'all'));
    $type   = strtolower($request->input('type', 'both')); // top|bottom|both
    $limit  = (int) $request->input('limit', 5);

    switch ($period) {
        case 'day':
            $from = Carbon::today()->startOfDay()->toDateTimeString();
            break;
        case 'week':
            $from = Carbon::now()->startOfWeek()->toDateTimeString();
            break;
        case 'month':
            $from = Carbon::now()->startOfMonth()->toDateTimeString();
            break;
        case 'year':
            $from = Carbon::now()->startOfYear()->toDateTimeString();
            break;
        default:
            $from = null;
            break;
    }

    $dateCondMG = $from ? " AND mg.created_at >= '{$from}'" : '';
    $dateCondTM = $from ? " AND tm.created_at >= '{$from}'" : '';

    $roleFilter = " (roles.name IS NULL OR roles.name != 'superadmin') ";

    $operationsSub = "
        (SELECT COUNT(*)
         FROM user_phones up
         JOIN message_groups mg ON mg.user_phone_id = up.id
         WHERE up.user_id = users.id
         {$dateCondMG}
        )";

    $messagesSub = "
        (SELECT COUNT(*)
         FROM user_phones up2
         JOIN message_groups mg2 ON mg2.user_phone_id = up2.id
         JOIN telegram_messages tm ON tm.message_group_id = mg2.id
         WHERE up2.user_id = users.id
           AND tm.status = 'sent'
           {$dateCondTM}
        )";

    $baseQuery = DB::table('users')
        ->leftJoin('roles', 'roles.id', '=', 'users.role_id')
        ->whereRaw($roleFilter)
        ->selectRaw("users.id, users.name, users.email, {$operationsSub} as operations_count, {$messagesSub} as messages_count");

    if ($type === 'both') {
        $top = (clone $baseQuery)
            ->orderByDesc('operations_count')
            ->orderByDesc('messages_count')
            ->limit($limit)
            ->get();

        $bottom = (clone $baseQuery)
            ->whereRaw("{$operationsSub} > 0")
            ->orderByRaw("{$operationsSub} ASC")
            ->orderByRaw("{$messagesSub} ASC")
            ->limit($limit)
            ->get();

        return response()->json([
            'generated_at' => Carbon::now()->toDateTimeString(),
            'period' => $period,
            'type' => 'both',
            'limit' => $limit,
            'top' => $top,
            'bottom' => $bottom,
        ]);
    }

    if ($type === 'top') {
        $users = $baseQuery
            ->orderByDesc('operations_count')
            ->orderByDesc('messages_count')
            ->limit($limit)
            ->get();
    } else {
        $users = $baseQuery
            ->whereRaw("{$operationsSub} > 0")
            ->orderByRaw("{$operationsSub} ASC")
            ->orderByRaw("{$messagesSub} ASC")
            ->limit($limit)
            ->get();
    }

    return response()->json([
        'generated_at' => Carbon::now()->toDateTimeString(),
        'period' => $period,
        'type' => $type,
        'limit' => $limit,
        'data' => $users,
    ]);
}


    public function topUsersByTelegramStatus(Request $request)
    {
        // params
        $status = $request->input('status', 'failed'); // default failed
        $limit  = $request->input('limit', 5); // default top 5
        $period = strtolower($request->input('period', 'all')); // optional

        // period filter
        switch ($period) {
            case 'day':
                $from = Carbon::today()->startOfDay()->toDateTimeString();
                break;
            case 'week':
                $from = Carbon::now()->startOfWeek()->toDateTimeString();
                break;
            case 'month':
                $from = Carbon::now()->startOfMonth()->toDateTimeString();
                break;
            case 'year':
                $from = Carbon::now()->startOfYear()->toDateTimeString();
                break;
            default:
                $from = null;
                break;
        }

        $dateCond = $from ? " AND tm.created_at >= '{$from}'" : '';

        // Role filter (exclude superadmin)
        $roleFilter = " (roles.name IS NULL OR roles.name != 'superadmin') ";

        // Subquery: count telegram_messages by status per user
        $messagesByStatusSub = "
        (SELECT COUNT(*)
         FROM user_phones up
         JOIN message_groups mg ON mg.user_phone_id = up.id
         JOIN telegram_messages tm ON tm.message_group_id = mg.id
         WHERE up.user_id = users.id
           AND tm.status = '{$status}'
           {$dateCond}
        )";

        $statusMapping = [
            'sent' => 'Yuborilgan',
            'failed' => 'Yuborilmagan',
            'pending' => 'Kutilayotgan',
            'draft' => 'Qoralama',
            'cancelled' => 'Bekor qilingan',
            'scheduled' => 'Rejalashtirilgan',
        ];
        $users = DB::table('users')
            ->leftJoin('roles', 'roles.id', '=', 'users.role_id')
            ->whereRaw($roleFilter)
            ->selectRaw("users.id, users.name, users.email, {$messagesByStatusSub} as messages_count")
            ->orderByDesc('messages_count')
            ->limit((int)$limit)
            ->get()
            ->map(function ($r) use ($status, $statusMapping) {
                $statusText = $statusMapping[$status] ?? $status;
                return [
                    'id' => (int)$r->id,
                    'name' => $r->name ?? '—',
                    'email' => $r->email ?? null,
                    'status' => $status,
                    'messages_count' => (int)$r->messages_count,
                    'messages_text' => number_format((int)$r->messages_count) . " ta {$statusText} habar",
                ];
            });


        return response()->json([
            'generated_at' => Carbon::now()->toDateTimeString(),
            'status' => $status,
            'limit' => $limit,
            'period' => $period,
            'top' => $users,
        ]);
    }



    /**
     * Realtime dashboard (yengil, DB aggregate-lar bilan).
     * Bu endpoint har 5 soniyaga yaqin poll qilinadi — shuning uchun relationlarni yuklamaymiz,
     * withCount / withMin / withMax kabi aggregate-lardan foydalanamiz.
     */
    public function dashboardRealtime(Request $request)
    {
        $perPage = (int)$request->query('per_page', 10); // frontend ehtiyojiga qarab o'zgartiring

        $baseQuery = MessageGroup::query()
            // ->where('status', 'pending')
            ->with('phone.user') // for creator info (phone->user)
            ->withCount('messages')
            ->withCount(['messages as sent_count' => function ($q) {
                $q->where('status', 'sent');
            }])
            ->withMin('messages', 'created_at')
            ->withMax('messages', 'sent_at');



        $groups = $baseQuery->latest()->limit($perPage)->get();

        $payload = $groups->map(function ($g) {
            $messagesCount = (int)($g->messages_count ?? 0);
            $sentCount = (int)($g->sent_count ?? 0);
            $percent = $messagesCount > 0 ? (int) round(($sentCount / $messagesCount) * 100) : 0;

            return [
                'id' => $g->id,
                'creator' => $g->phone && $g->phone->user ? [
                    'id' => $g->phone->user->id,
                    'name' => $g->phone->user->name,
                ] : null,
                'messages_count' => $messagesCount,
                'sent_count' => $sentCount,
                'percent' => $percent,
                'status' => $g->status,
                'started_at' => $g->messages_min_created_at ? Carbon::parse($g->messages_min_created_at)->toDateTimeString() : null,
                'finished_at' => $g->messages_max_sent_at ? Carbon::parse($g->messages_max_sent_at)->toDateTimeString() : null,
                'text_preview' => mb_substr($g->message_text ?? '-', 0, 120),
            ];
        });

        return response()->json([
            'fetched_at' => Carbon::now()->toDateTimeString(),
            'count' => $payload->count(),
            'groups' => $payload,
        ]);
    }
}
