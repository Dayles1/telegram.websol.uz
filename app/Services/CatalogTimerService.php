<?php

namespace App\Services;

use App\Models\Catalog;
use App\Models\CatalogUsage;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CatalogTimerService
{
    /**
     * Urinish: kataloglarni telefon bo'yicha rezerv qilish (DB only).
     *
     * @param array $catalogIds
     * @param int $userPhoneId
     * @param int $userId  Auth::id()
     * @return array ['allowed' => bool, 'blocked' => [catalogId => seconds_left]]
     */
    public function reserveCatalogsForPhone(array $catalogIds, int $userPhoneId, int $userId): array
    {
        $now = Carbon::now();
        $blocked = [];

        // DB transaction: tekshirish -> (agar hamma ok bo'lsa) yangilash/yaratish
        DB::beginTransaction();

        try {
            // 1) yuklab olamiz va lock qo'yamiz faqat mavjud catalog_usages satrlari uchun.
            // Avvalo cataloglarni o'qiymiz (owner va is_global tekshirish uchun)
            $catalogs = Catalog::whereIn('id', $catalogIds)->get()->keyBy('id');

            // 2) oldindan tekshirish: agar catalog mavjud emas — o'tkazib yubor yoki block qil
            foreach ($catalogIds as $cid) {
                $catalog = $catalogs->get($cid);
                if (! $catalog) {
                    // mavjud bo'lmagan catalog bilan davom etmaymiz
                    $blocked[$cid] = [
                        'reason' => 'not_found',
                        'seconds_left' => null,
                        'name' => $catalog->title,
                    ];
                }
            }
            if (! empty($blocked)) {
                DB::rollBack();
                return ['allowed' => false, 'blocked' => $blocked];
            }

            // 3) Endi har bir catalog uchun timer va owner tekshirish
            // Saqlash uchun array (existing usage models) 
            $toUpdate = [];
            $toCreate = [];

            foreach ($catalogIds as $cid) {
                /** @var Catalog $catalog */
                $catalog = $catalogs->get($cid);

                // agar catalog global bo'lmasa: faqat owner (user_id) ruxsatiga ega
                if (! $catalog->is_global && $catalog->user_id !== $userId) {
                    $blocked[$cid] = [
                        'reason' => 'no_permission',
                        'seconds_left' => null,
                        'name' => $catalog->title,
                    ];
                    continue;
                }

                $minutes = (int) ($catalog->timer_minutes ?? 0);
                if ($minutes <= 0) {
                    // timer yo'q — ruxsat, ammo usage row kerak emas
                    continue;
                }

                // lockForUpdate on the usage row if exists
                $usage = CatalogUsage::where('catalog_id', $cid)
                    ->where('user_phone_id', $userPhoneId)
                    ->lockForUpdate()
                    ->first();

                if ($usage) {
                    // agar oxirgi ishlatish hali cooldown ichida bo'lsa — block qilamiz
                    if ($usage->last_used_at) {
                        $availableAt = Carbon::parse($usage->last_used_at)->addMinutes($minutes);
                        if ($availableAt->greaterThan($now)) {
                            $secs = $availableAt->diffInSeconds($now);
                            $blocked[$cid] = [
                                'reason' => 'cooldown',
                                'seconds_left' => $secs,
                                'name' => $catalog->title,
                            ];
                            continue;
                        }
                    }
                    // ruxsat, keyinroq yangilash uchun ro'yxatga qo'shamiz
                    $toUpdate[] = $usage;
                } else {
                    // mavjud emas — keyinroq yaratamiz (birozdan keyin insertni sinovdan o'tkazamiz)
                    $toCreate[] = $cid;
                }
            }

            if (! empty($blocked)) {
                DB::rollBack();
                return ['allowed' => false, 'blocked' => $blocked];
            }

            // 4) Hammasi ok — endi yangilaymiz / yaratamiz (last_used_at = now)
            foreach ($toUpdate as $u) {
                $u->last_used_at = $now;
                $u->save();
            }

            foreach ($toCreate as $cid) {
                try {
                    CatalogUsage::create([
                        'catalog_id' => $cid,
                        'user_phone_id' => $userPhoneId,
                        'last_used_at' => $now,
                    ]);
                } catch (\Illuminate\Database\QueryException $e) {
                    // Ehtimol boshqa transaction shu paytda row yaratgan — shunda uni yangilab olamiz
                    Log::warning("CatalogUsage create race for catalog {$cid}, phone {$userPhoneId}: " . $e->getMessage());
                    $existing = CatalogUsage::where('catalog_id', $cid)
                        ->where('user_phone_id', $userPhoneId)
                        ->lockForUpdate()
                        ->first();
                    if ($existing) {
                        // double-check cooldown just in case
                        $catalog = $catalogs->get($cid);
                        $minutes = (int) ($catalog->timer_minutes ?? 0);
                        $availableAt = Carbon::parse($existing->last_used_at)->addMinutes($minutes);
                        if ($availableAt->greaterThan($now)) {
                            $secs = $availableAt->diffInSeconds($now);
                            $blocked[$cid] = [
                                'reason' => 'cooldown',
                                'seconds_left' => $secs,
                                'name' => $catalog->title,
                            ];
                            DB::rollBack();
                            return ['allowed' => false, 'blocked' => $blocked];
                        }
                        $existing->last_used_at = $now;
                        $existing->save();
                    } else {
                        // no existing row and insert failed for other reason -> rethrow
                        throw $e;
                    }
                }
            }

            DB::commit();
            return ['allowed' => true, 'blocked' => []];
        } catch (\Throwable $e) {
            DB::rollBack();
            // log qilamiz va block deb qaytaramiz (server error bo'lishi mumkin)
            Log::error('reserveCatalogsForPhone failed: ' . $e->getMessage());
            return ['allowed' => false, 'blocked' => ['_internal' => ['reason' => 'error', 'message' => $e->getMessage()]]];
        }
    }
}
