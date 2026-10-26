<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MessageGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MessageGroupController extends Controller
{
    // Dashboard (pending message groups)



    // Cancel pending messages in a group
    public function cancelMassMessage($groupId)
    {
        $group = MessageGroup::with(['messages', 'infinityMessages'])->find($groupId);

        if (!$group) {
            return response()->json([
                'status' => 'error',
                'message' => 'Message group not found!'
            ], 404);
        }

        // Agar group allaqachon canceled bo'lsa
        if ($group->status === 'canceled') {
            return response()->json([
                'status' => 'success',
                'message' => 'Group already canceled'
            ], 200);
        }

        // Cancel qilinadigan messages turini aniqlaymiz
        if ($group->status === 'pending') {
            $group->messages()->where('status', 'pending')->update(['status' => 'canceled']);
        } elseif ($group->status === 'infinity_pending') {
            $group->infinityMessages()->where('status', 'pending')->update(['status' => 'canceled']);
        }

        // Endi group statusini cancel qilamiz
        $group->status = 'canceled';
        $group->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Pending messages successfully canceled'
        ], 200);
    }


     public function show($groupId)
{
    $group = MessageGroup::with(['catalogs', 'messages', 'phone.user'])
        ->find($groupId);

    if (!$group) {
        return response()->json([
            'status'  => 'error',
            'message' => 'Habarlar topilmadi'
        ], 404);
    }

    // --- 1) Umumiy statistika tayyorlab olamiz ---
    $total   = $group->messages->count();
    $sent    = $group->messages->where('status', 'sent')->count();
    $pending = $group->messages->where('status', 'pending')->count();
    $failed  = $group->messages->where('status', 'failed')->count();
    $canceled = $group->messages->where('status', 'canceled')->count();

    // --- 2) Guruh statusini yangilaymiz ---
    if ($pending > 0) {
        $group->status = 'pending';
    } elseif ($failed === $total && $total > 0) {
        $group->status = 'failed';
    } elseif ($sent === $total && $total > 0) {
        $group->status = 'sent';
    } else {
        $group->status = 'pending';
    }

    $group->saveQuietly();

    // --- 3) Peer bo‘yicha guruhlash ---
    $peersGrouped = $group->messages
        ->groupBy('peer')
        ->map(function ($msgs, $peer,$group) {

            return [
                'peer'          => $peer,
                'messages'      => $msgs->values(),
                'text'          => $group->message_text,
                'total'         => $msgs->count(),

                'sent_count'    => $msgs->where('status', 'sent')->count(),
                'pending_count' => $msgs->where('status', 'pending')->count(),
                'failed_count'  => $msgs->where('status', 'failed')->count(),
                'canceled_count'=> $msgs->where('status', 'canceled')->count(),

                'created_at'    => optional($msgs->min('created_at'))->toDateTimeString(),
                'sent_at'       => optional($msgs->max('sent_at'))->toDateTimeString(),
            ];
        })
        ->values();

    // --- 4) Overview qaytarish ---
    $overview = [
        'total_messages' => $total,
        'sent'           => $sent,
        'pending'        => $pending,
        'failed'         => $failed,
        'canceled'       => $canceled,

        'started_at'     => optional($group->messages->min('created_at'))->toDateTimeString(),
        'finished_at'    => optional($group->messages->max('sent_at'))->toDateTimeString(),
    ];

    return response()->json([
        'status'  => 'success',
        'data'    => [
            'group'    => $group,
            'overview' => $overview,
            'peers'    => $peersGrouped,
        ]
    ]);
}




   public function history(Request $request)
{
    $search = $request->input('search');

    // Yuklashni cheklab, kerakli maydonlarni belgilash mumkin (performance uchun)
    $query = MessageGroup::with([
        'phone.user',
        'messages' => function ($q) {
            // kerakli ustunlarni tanlab olish — agar messages katta bo'lsa samaradorlik oshadi
            $q->select('id', 'message_group_id', 'peer', 'message_text', 'status', 'sent_at', 'created_at', 'updated_at');
        },
    ])->withCount('messages');

    // Faqat o'z telefonlariga tegishli guruhlar (oddiy user uchun)
    if (!Auth::user()->hasRole('superadmin')) {
        $query->whereHas('phone', fn($q) => $q->where('user_id', Auth::id()));
    }

    // Qidiruv: group.message_text ham qamrab olinadi (yangi struktura)
    $query->when($search, function ($q, $search) {
        $q->where(function ($q) use ($search) {
            $q->whereHas('phone.user', fn($qu) => $qu->where('name', 'like', "%{$search}%"))
              ->orWhereHas('phone', fn($qu) => $qu->where('phone', 'like', "%{$search}%"))
              ->orWhere('message_text', 'like', "%{$search}%") // <-- group-level search
              ->orWhereHas('messages', fn($qu) => $qu->where('message_text', 'like', "%{$search}%"));
        });
    });

    $groups = $query->latest()->paginate(15);

    $groups->getCollection()->transform(function ($group) {

        $messages = $group->messages; // Collection

        $pending = $messages->where('status', 'pending')->count();
        $sent    = $messages->where('status', 'sent')->count();
        $failed  = $messages->where('status', 'failed')->count();
        $total   = $group->messages_count ?? $messages->count();

        // Hisoblangan status
        if ($total === 0) {
            $calculatedStatus = $group->status ?? 'pending';
        } elseif ($pending > 0) {
            $calculatedStatus = 'pending';
        } elseif ($failed === $total) {
            $calculatedStatus = 'failed';
        } elseif ($sent === $total) {
            $calculatedStatus = 'sent';
        } else {
            $calculatedStatus = 'pending';
        }

        // Faqat agar o'zgargan bo'lsa bazaga yozamiz (DB yozuvlarni kamaytiradi)
        if ($group->status !== $calculatedStatus) {
            $group->status = $calculatedStatus;
            $group->saveQuietly();
        }

        // So'nggi yuborilgan xabarni sent_at ga qarab olamiz, sent_at null bo'lsa updated_at ga fallback qilamiz
        $lastSentMessage = $messages
            ->whereNotNull('sent_at')
            ->sortByDesc('sent_at')
            ->first()
            ?? $messages->sortByDesc('updated_at')->first();

        $group->type = 'messages';
        $group->peers_count = $messages->pluck('peer')->unique()->filter()->count();
        $group->total_messages = $total;
        $group->messages_status_count = [
            'pending' => $pending,
            'sent'    => $sent,
            'failed'  => $failed,
        ];

        $group->text = $group->message_text ?? $messages->first()?->message_text ?? '—';

        $group->creator = $group->phone?->user?->name ?? '—';
        $group->phone_number = $group->phone?->phone ?? '—';

        $group->started_at = $group->created_at
            ? \Carbon\Carbon::parse($group->created_at)->format('d.m.Y H:i')
            : '—';

        $group->last_sent_at = $lastSentMessage?->sent_at
            ? \Carbon\Carbon::parse($lastSentMessage->sent_at)->format('d.m.Y H:i')
            : ($lastSentMessage?->updated_at
                ? \Carbon\Carbon::parse($lastSentMessage->updated_at)->format('d.m.Y H:i')
                : 'Hali yuborilmagan');

        return $group;
    });

    return response()->json([
        'status' => 'success',
        'type'   => 'history',
        'data'   => $groups
    ], 200);
}



}
