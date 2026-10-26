<?php

namespace App\Http\Controllers;

use App\Models\MessageGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class MessageGroupController extends Controller
{
    public function dashboard()
    {
        $baseQuery = MessageGroup::with('phone', 'messages', 'catalogs')
            ->where('status', 'pending');

        if (Auth::user()->hasRole('superadmin')) {
            $groups = $baseQuery->latest()->paginate(10);
        } else {
            $groups = $baseQuery
                ->whereHas('phone', function ($q) {
                    $q->where('user_id', Auth::id());
                })
                ->latest()
                ->paginate(10);
        }

        // Update statuses if needed (note: this loads messages relation)
        foreach ($groups as $group) {
            $messages = $group->messages;

            if ($messages->isEmpty()) {
                continue;
            }

            if ($messages->every(fn($m) => $m->status === 'sent')) {
                if ($group->status !== 'sent') {
                    $group->status = 'sent';
                    $group->save();
                }
            } elseif ($messages->every(fn($m) => $m->status !== 'pending')) {
                if ($group->status !== 'failed') {
                    $group->status = 'failed';
                    $group->save();
                }
            }
        }

        // Prepare frontend fields
        foreach ($groups as $group) {
            $group->messages_count = $group->messages->count();
            $group->creator = $group->phone->user;
            $group->sent_count = $group->messages->where('status', 'sent')->count();
            $group->text = $group->message_text ?? $group->messages->first()->message_text ?? '-';
            $group->started_at = $group->messages->min('created_at');
            $group->finished_at = $group->messages->max('sent_at');
        }

        return view('messages.index', compact('groups'));
    }

    /**
     * Returns HTML partials (rows + links) for full refresh via AJAX.
     */
    public function dashboardData(Request $request)
    {
        $page = (int) $request->query('page', 1);
        $perPage = 10;

        $baseQuery = MessageGroup::with('phone', 'messages', 'catalogs')
            ->where('status', 'pending');

        if (!Auth::user()->hasRole('superadmin')) {
            $baseQuery = $baseQuery->whereHas('phone', function ($q) {
                $q->where('user_id', Auth::id());
            });
        }

        $groups = $baseQuery->latest()->paginate($perPage, ['*'], 'page', $page);

        foreach ($groups as $group) {
            $group->messages_count = $group->messages->count();
            $group->creator = $group->phone->user;
            $group->sent_count = $group->messages->where('status', 'sent')->count();
            $group->text = $group->message_text ?? $group->messages->first()->message_text ?? '-';
            $group->started_at = $group->messages->min('created_at');
            $group->finished_at = $group->messages->max('sent_at');
        }

        $html = view('messages._rows', compact('groups'))->render();
        $links = view('messages._links', compact('groups'))->render();

        return response()->json([
            'html' => $html,
            'links' => $links,
        ]);
    }

    /**
     * Lightweight stats JSON for frequent polling (1s).
     * Uses DB aggregates to avoid loading all messages for each poll.
     */
    public function dashboardStats(Request $request)
    {
        $page = (int) $request->query('page', 1);
        $perPage = 10;

        $baseQuery = MessageGroup::where('status', 'pending')
            ->with('phone', 'catalogs')
            // aggregate counts without loading messages
            ->withCount('messages')
            ->withCount(['messages as sent_count' => function ($q) {
                $q->where('status', 'sent');
            }])
            ->withMin('messages', 'created_at')
            ->withMax('messages', 'sent_at');

        if (!Auth::user()->hasRole('superadmin')) {
            $baseQuery = $baseQuery->whereHas('phone', function ($q) {
                $q->where('user_id', Auth::id());
            });
        }

        $groups = $baseQuery->latest()->paginate($perPage, ['*'], 'page', $page);

        $payload = [];
        foreach ($groups as $g) {
            $messagesCount = $g->messages_count ?? 0;
            $sentCount = $g->sent_count ?? 0;
            $percent = $messagesCount > 0 ? round(($sentCount / $messagesCount) * 100) : 0;

            $payload[] = [
                'id' => $g->id,
                'messages_count' => (int) $messagesCount,
                'sent_count' => (int) $sentCount,
                'percent' => (int) $percent,
                'status' => $g->status,
                'started_at' => $g->messages_min_created_at ? \Carbon\Carbon::parse($g->messages_min_created_at)->format('Y-m-d H:i') : null,
                'finished_at' => $g->messages_max_sent_at ? \Carbon\Carbon::parse($g->messages_max_sent_at)->format('Y-m-d H:i') : null,

                'text_preview' => mb_substr($g->message_text ?? '-', 0, 120),
            ];
        }

        return response()->json([
            'page' => $page,
            'per_page' => $perPage,
            'groups' => $payload,
        ]);
    }



    public function cancelMassMessage(Request $request, $groupId)
    {
        // Guruhni topamiz
        $group = MessageGroup::find($groupId);

        if (!$group) {
            return back()->withErrors(['error' => 'Message group topilmadi!']);
        }

        try {
            // Faqat pending holatidagi xabarlarni bekor qilamiz
            $group->messages()->where('status', 'pending')->update(['status' => 'canceled']);

            // Agar barcha xabarlar endi pending bo‘lmasa, guruh statusini ham yangilaymiz
            $stillPending = $group->messages()->where('status', 'pending')->count();
            if ($stillPending === 0) {
                $group->status = 'canceled';
                $group->save();
            }

            return back()->with('success', 'Faqat pending xabarlar muvaffaqiyatli bekor qilindi.');
        } catch (\Exception $e) {
            return back()->withErrors(['error' => 'Bekor qilishda xatolik yuz berdi: ' . $e->getMessage()]);
        }
    }
    public function show($groupId)
    {
        $group = MessageGroup::with('catalogs', 'messages', 'phone.user')->findOrFail($groupId);

        // Normalize catalog => peers map
        $catalogMap = $group->catalogs->mapWithKeys(function ($catalog) {
            $peers = $catalog->peers;
            if (is_string($peers)) {
                $decoded = json_decode($peers, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $peersArr = $decoded;
                } else {
                    $peersArr = array_filter(array_map('trim', explode(',', $peers)));
                }
            } elseif (is_array($peers)) {
                $peersArr = $peers;
            } else {
                $peersArr = [];
            }
            return [$catalog->id => array_values(array_filter($peersArr))];
        });

        $catalogPeers = collect($catalogMap->values())->flatten()->filter()->unique()->values();
        $messagePeers = $group->messages->pluck('peer')->filter()->unique()->values();
        $allPeers = $catalogPeers->merge($messagePeers)->unique()->values();

        $peersGrouped = $allPeers->map(function ($peer) use ($group, $catalogMap) {
            $peer = (string) $peer;
            $msgs = $group->messages->where('peer', $peer);

            // status priority: pending > failed > sent > canceled > unknown
            $status = 'unknown';
            if ($msgs->where('status', 'pending')->count() > 0) $status = 'pending';
            elseif ($msgs->where('status', 'failed')->count() > 0) $status = 'failed';
            elseif ($msgs->where('status', 'sent')->count() > 0) $status = 'sent';
            elseif ($msgs->where('status', 'canceled')->count() > 0) $status = 'canceled';

            $last = $msgs->whereNotNull('sent_at')->sortByDesc('sent_at')->first()
                ?? $msgs->sortByDesc('updated_at')->first();
            $lastAt = $last?->sent_at ?? $last?->updated_at ?? null;

            $foundCatalogId = null;
            foreach ($catalogMap as $catalogId => $peersArr) {
                if (in_array($peer, (array)$peersArr, true)) {
                    $foundCatalogId = $catalogId;
                    break;
                }
            }

            $trim = trim($peer);
            $tgLink = null;
            if (preg_match('#^https?://#i', $trim)) $tgLink = $trim;
            elseif (str_starts_with($trim, '@')) $tgLink = 'https://t.me/' . ltrim($trim, '@');
            elseif (preg_match('/^[A-Za-z0-9_]{5,}$/', $trim)) $tgLink = 'https://t.me/' . $trim;

            $failedKeys = $msgs->where('status', 'failed')->pluck('error_key')->filter()->unique()->values()->all();

            return (object) [
                'peer' => $peer,
                'tg_link' => $tgLink,
                'in_catalog' => (bool)$foundCatalogId,
                'catalog_id' => $foundCatalogId,
                'status' => $status,
                'sent_at' => $lastAt,
                'failed_keys' => $failedKeys,
            ];
        });

        // Overview totals & percentages by status
        $totals = [
            'total' => $group->messages->count(),
            'sent' => $group->messages->where('status', 'sent')->count(),
            'failed' => $group->messages->where('status', 'failed')->count(),
            'pending' => $group->messages->where('status', 'pending')->count(),
            'canceled' => $group->messages->where('status', 'canceled')->count(),
        ];
        $total = max(1, $totals['total']); // avoid division by zero

        $percentages = [
            'sent' => round($totals['sent'] / $total * 100, 1),
            'failed' => round($totals['failed'] / $total * 100, 1),
            'pending' => round($totals['pending'] / $total * 100, 1),
            'canceled' => round($totals['canceled'] / $total * 100, 1),
        ];

        return view('messages.show', compact('group', 'peersGrouped', 'totals', 'percentages'));
    }






    public function history(Request $request)
    {
        $search = $request->input('search');

        // Bosh so'rovni tayyorlaymiz
        $query = MessageGroup::with('phone', 'messages', 'catalogs')->latest();

        // Agar superadmin bo'lmasa — faqat o'z telefonlariga tegishli guruhlar
        if (!Auth::user()->hasRole('superadmin')) {
            $query->whereHas('phone', function ($q) {
                $q->where('user_id', Auth::id());
            });
        }

        // Qidiruvni qo'llash — barcha relevant maydonlarni bitta guruh ichida tekshiradi
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('phone.user', function ($q2) use ($search) {
                    $q2->where('name', 'like', "%{$search}%");
                })
                    ->orWhereHas('phone', function ($q2) use ($search) {
                        $q2->where('phone', 'like', "%{$search}%");
                    })
                    // Qidiruvni endi group.message_text bo'yicha ham qilamiz (yangi format)
                    ->orWhere('message_text', 'like', "%{$search}%")
                    // Va backward-compatible: eski telegram_messages.message_text bo'yicha ham
                    ->orWhereHas('messages', function ($q2) use ($search) {
                        $q2->where('message_text', 'like', "%{$search}%");
                    })
                    ->orWhereHas('catalogs', function ($q2) use ($search) {
                        $q2->where('title', 'like', "%{$search}%");
                    });
            });
        }

        $groups = $query->paginate(15);

        foreach ($groups as $group) {
            $group->messages_count = $group->messages->count();
            $peerCount = $group->catalogs
                ->pluck('peers')
                ->flatten()
                ->unique()
                ->count();

            $group->creator = $group->phone->user;
            $group->sent_count = $group->messages->where('status', 'sent')->count();

            // Prefer group->message_text (yangi), ammo eskilar uchun fallback qoldiramiz
            $group->text = $group->message_text ?? $group->messages->first()->message_text ?? '-';

            $group->started_at = $group->messages->min('created_at');
            $group->finished_at = $group->messages->max('sent_at');
        }

        return view('messages.history', compact('groups', 'search'));
    }
}
