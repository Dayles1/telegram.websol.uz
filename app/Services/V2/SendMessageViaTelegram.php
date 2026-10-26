<?php

namespace App\Services\V2;

use App\Jobs\V2\SendMassMessagesJob;
use App\Models\Catalog;
use App\Models\MessageGroup;
use App\Models\TelegramMessage;
use App\Models\UserPhone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendMessageViaTelegram
{
    public function sendScheduledMessage(array $data): array
    {
        $phone = UserPhone::with('messageGroups')->find($data['phone_id']);

        if (! $phone) {
            return [
                'success' => false,
                'message' => 'Telefon topilmadi.',
                'status'  => 404,
            ];
        }

        $pendingMessages = $phone->messageGroups->where('status', 'pending')->count();

        if ($pendingMessages >= 2) {
            return [
                'success' => false,
                'message' => 'Bir vaqtning o‘zida faqat 2 ta xabar jo‘natish mumkin.',
                'status'  => 429,
            ];
        }

        $durationHours   = (int) $data['duration'];
        $interval        = max(1, (int) ($data['interval'] ?? 61));
        $durationMinutes  = $durationHours * 60;
        $loopCount        = max(1, (int) ceil($durationMinutes / $interval));

        try {
            DB::beginTransaction();

            $group = MessageGroup::create([
                'user_phone_id' => $phone->id,
                'status'        => 'pending',
                'message_text'   => $data['message'],
            ]);

            $catalogs = Catalog::whereIn('id', $data['catalog_ids'])->get();
            $group->catalogs()->attach($catalogs->pluck('id')->toArray());

            $peers = [];

            foreach ($catalogs as $catalog) {
                $catalogPeers = is_array($catalog->peers)
                    ? $catalog->peers
                    : json_decode($catalog->peers ?? '[]', true);

                if (! empty($catalogPeers)) {
                    $peers = array_merge($peers, $catalogPeers);
                }
            }

            $peers = array_values(array_unique($peers));

            if (empty($peers)) {
                DB::rollBack();

                return [
                    'success' => false,
                    'message' => 'Peerlar topilmadi',
                    'status'  => 422,
                ];
            }

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
                        'created_at'       => now(),
                        'updated_at'       => now(),
                    ];

                    if (count($insertChunk) >= 1000) {
                        TelegramMessage::insert($insertChunk);
                        $insertChunk = [];
                    }
                }
            }

            if (! empty($insertChunk)) {
                TelegramMessage::insert($insertChunk);
            }

            $dispatchAt = now();

            for ($batchNo = 1; $batchNo <= $loopCount; $batchNo++) {
                if ($batchNo === 1) {
                    $dispatchAt = now();
                } else {
                    $randomSeconds = rand(30, 70);
                    $dispatchAt = $dispatchAt->copy()
                        ->addMinutes($interval)
                        ->addSeconds($randomSeconds);
                }

                Log::info('Dispatching SendMassMessagesJob', [
                    'batch_no'     => $batchNo,
                    'interval_min' => $interval,
                    'dispatch_at'  => $dispatchAt->format('Y-m-d H:i:s'),
                ]);
                TelegramMessage::where('message_group_id', $group->id)
                    ->where('batch_no', $batchNo)
                    ->update([
                        'send_at' => $dispatchAt
                    ]);

                SendMassMessagesJob::dispatch($group->id, $batchNo)
                    ->onQueue('telegram')
                    ->delay($dispatchAt);
            }

            DB::commit();

            return [
                'success'  => true,
                'message'  => 'Group yaratildi',
                'status'   => 200,
                'group_id' => $group->id,
            ];
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Mass message error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => 'Xatolik yuz berdi',
                'status'  => 500,
            ];
        }
    }
}