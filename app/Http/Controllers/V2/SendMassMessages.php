<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\SendMassMessagesRequest;
use App\Jobs\V2\SendMassMessagesJob;
use App\Models\Catalog;
use App\Models\MessageGroup;
use App\Models\TelegramMessage;
use App\Models\UserPhone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


class SendMassMessages extends Controller
{
    public function sendMassMessages(SendMassMessagesRequest $request)
    {
        $phone = UserPhone::with('messageGroups')->find($request->phone_id);

        if (!$phone) {
            if (request()->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Telefon topilmadi.'
                ], 404);
            }

            return back()->with('error', 'Telefon topilmadi.');
        }
        $pendingMessages = $phone->messageGroups->where('status', 'pending')->count();
        if ($pendingMessages >= 2) {
            if (request()->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bir vaqtning o‘zida faqat 2 ta xabar jo‘natish mumkin.',
                ], 429);
            }

            return back()->with('error', 'Bir vaqtning o‘zida faqat 2 ta xabar jo‘natish mumkin.');
        }
        $durationHours    = (int) $request->duration;
        $interval         = max(1, (int) ($request->interval ?? 61));
        $durationMinutes  = $durationHours * 60;
        $loopCount        = max(1, (int) ceil($durationMinutes / $interval));
        
        try {
            DB::beginTransaction();

            $group = MessageGroup::create([
                'user_phone_id' => $phone->id,
                'status'        => 'pending',
                'message_text'   => $request->message,
            ]);

            $catalogs = Catalog::whereIn('id', $request->catalog_ids)->get();
            $group->catalogs()->attach($catalogs->pluck('id')->toArray());

            $peers = [];
            foreach ($catalogs as $catalog) {
                $catalogPeers = is_array($catalog->peers)
                    ? $catalog->peers
                    : json_decode($catalog->peers ?? '[]', true);
                if (!empty($catalogPeers)) {
                    $peers = array_merge($peers, $catalogPeers);
                }
            }
            $peers = array_values(array_unique($peers));

            if (empty($peers)) {
                if (request()->expectsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Peerlar topilmadi'
                    ], 422);
                }
                return back()
                    ->withInput()
                    ->withErrors([
                        'peers' => 'Peerlar topilmadi'
                    ]);
            }

            $now = now();
            $base = now();

            $insertChunk = [];

            foreach ($peers as $peer) {
                for ($i = 0; $i < $loopCount; $i++) {
                    $insertChunk[] = [
                        'message_group_id' => $group->id,
                        'peer'             => $peer,
                        'send_at'          => $base->copy()->addMinutes($i * $interval),
                        'status'           => 'pending',
                        'batch_no'         => $i + 1,
                    ];

                    if (count($insertChunk) >= 1000) {
                        TelegramMessage::insert($insertChunk);
                        $insertChunk = [];
                    }
                }
            }

            if (!empty($insertChunk)) {
                TelegramMessage::insert($insertChunk);
            }

            $dispatchAt = now();

            foreach (range(1, $loopCount) as $batchNo) {
                if ($batchNo === 1) {
                    $dispatchAt = now();
                } else {
                    $randomSeconds = rand(30, 70);
                    $dispatchAt = $dispatchAt->copy()
                        ->addMinutes($interval)
                        ->addSeconds($randomSeconds);
                }

                Log::info('Dispatching ExecJob', [
                    'batch_no'    => $batchNo,
                    'interval_min' => $interval,
                    'dispatch_at' => $dispatchAt->format('Y-m-d H:i:s'),
                ]);

                SendMassMessagesJob::dispatch($group->id, $batchNo)
                    ->onQueue('telegram')
                    ->delay($dispatchAt);
            }

            DB::commit();

           $message = 'Group yaratildi';

            if (request()->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => $message,
                ]);
            }

            return redirect()
                ->route('')
                ->with('success', $message);

        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Mass message error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Xatolik yuz berdi'
            ], 500);
        }
    }



}
