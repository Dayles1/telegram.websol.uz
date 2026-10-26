<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use App\Jobs\TestJob;
use App\Models\Catalog;
use App\Models\UserPhone;
use App\Models\MessageGroup;
use danog\MadelineProto\API;
use Illuminate\Http\Request;
use App\Jobs\TelegramAuthJob;
use App\Jobs\CompleteLoginJob;
use App\Jobs\TelegramLogoutJob;
use App\Jobs\TelegramVerifyJob;
use App\Models\InfinityMessage;
use App\Models\TelegramMessage;
use App\Jobs\SendTelegramMessage;
use danog\MadelineProto\Settings;
use Illuminate\Support\Facades\DB;
use App\Models\TelegramAuthSession;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use App\Events\TelegramMessageCreated;
use danog\MadelineProto\Settings\AppInfo;
use Illuminate\Support\Facades\Validator;

class TelegramController extends Controller
{

    
    public function authRequest(Request $r)
    {
        $phone = $r->phone;
        $user = Auth::user();
        $userId = $user->id;

        $sessionPath = storage_path("app/sessions/{$phone}_user_{$userId}.madeline");

        $session = TelegramAuthSession::create([
        'user_id' => $user->id,
        'phone' => $phone,
        'status' => 'sms_sent',
        'message' => 'SMS yuborildi'
    ]);

        if (File::exists($sessionPath)) {
            File::deleteDirectory($sessionPath);
            Log::info("Old session folder deleted for {$phone} and user ID {$userId}");
        }

        Log::info("Starting Telegram auth for {$phone} and user ID {$userId}");

        TelegramAuthJob::dispatch($phone, $user->id, $session->id)->onQueue('telegram');

        return response()->json(['status' => 'sms_sent', 'message' => 'SMS yuborildi']);
    }

    public function verifyCode(Request $r)
    {
        $validator = Validator::make($r->all(), [
            'phone' => 'required|string|min:9|max:20',
            'code'  => 'required|string|min:3|max:10',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'validation_error',
                'errors' => $validator->errors()
            ], 422);
        }

        $phone = $r->phone;
        $user = Auth::user();
        $userId = $user->id;

        // sessionni topamiz yoki yaratamiz
        $session = TelegramAuthSession::firstOrCreate(
            ['user_id' => $userId, 'phone' => $phone],
            ['status' => 'created', 'message' => 'Session created']
        );

        // increment attempts va statusni yangilash
        $session->increment('attempts');
        $session->update(['status' => 'processing', 'last_ping' => now()]);

        // dispatch verify job — job session_id oladi
        TelegramVerifyJob::dispatch($phone, $userId, $r->code, $session->id)->onQueue('telegram');

        return response()->json([
            'status' => 'processing',
            'session_id' => $session->id,
            'message' => 'Verify job dispatched'
        ]);
    }
    public function completeLogin(Request $r)
    {
        $validator = Validator::make($r->all(), [
            'phone'    => 'required|string|min:9|max:20',
            'password' => 'nullable|string|min:4|max:100',
            'session_id' => 'nullable|integer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'validation_error',
                'errors' => $validator->errors()
            ], 422);
        }

        $phone = $r->phone;
        $password = $r->password;
        $user = Auth::user();
        $userId = $user->id;

        if ($r->filled('session_id')) {
            $session = TelegramAuthSession::where('id', $r->session_id)->where('user_id', $userId)->first();
        } else {
            $session = TelegramAuthSession::where('phone', $phone)->where('user_id', $userId)->first();
        }

        if (!$session) {
            return response()->json(['status' => 'error', 'message' => 'Session topilmadi — avval verifyCode chaqiring'], 404);
        }

        // statusni yangilash va jobni chaqirish
        $session->update(['status' => 'processing', 'last_ping' => now()]);

        CompleteLoginJob::dispatch($phone, $userId, $password, $session->id)->onQueue('telegram');

        return response()->json([
            'status' => 'processing',
            'session_id' => $session->id,
            'message' => 'CompleteLogin job dispatched'
        ]);
    }

    public function sendInfinityMessages(Request $request)
    {
        $validated = $request->validate([
            'phone'       => 'required|exists:user_phones,phone',
            'catalog_ids' => ['required', 'array',],
            'catalog_ids.*' => ['integer', 'exists:catalogs,id'],
            'message'     => 'required|string|max:4096',
            'interval'    => 'nullable|integer|min:10|max:36000',
        ]);

        $interval  = $validated['interval'] ?? 60;

        $peers = collect();

        if (!empty($validated['catalog_ids'])) {
            $catalogs = Catalog::whereIn('id', $validated['catalog_ids'])->get();
            foreach ($catalogs as $catalog) {
                if ($catalog->peers) {
                    $peers = $peers->merge($catalog->peers);
                }
            }
        }

        $peers = $peers->unique()->values();
        if ($peers->isEmpty()) {
            return back()->withErrors(['peer' => 'Hech qanday peer topilmadi!']);
        }

        DB::beginTransaction();

        try {
            $userPhone = UserPhone::where('phone', $validated['phone'])
                ->where('user_id', Auth::id())
                ->firstOrFail();

            $group = MessageGroup::create([
                'user_phone_id' => $userPhone->id,
                'status' => 'infinity_pending',
                    'message_text'     => $validated['message'],
            ]);

            if (!empty($validated['catalog_ids'])) {
                $group->catalogs()->sync($validated['catalog_ids']);
            }

            $data = [];
            foreach ($peers as $peer) {
                $data[] = new InfinityMessage([
                    // 'message_text'     => $validated['message'],
                    'peer'             => $peer,
                    'interval'         => $interval,
                    'status'           => 'pending',
                    'starts_at'        => now(),
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            }

            $group->infinityMessages()->saveMany($data);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Xabarlar navbatga qo‘shildi!'
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Xatolik yuz berdi: ' . $e->getMessage()
            ], 500);
        }
    }
    public function cancelInfinityMessages($id)
    {
        DB::beginTransaction();

        try {
            $group = MessageGroup::with('infinityMessages')->findOrFail($id);

            $group->status = 'canceled';
            $group->save();

            if ($group->infinityMessages->isNotEmpty()) {
                foreach ($group->infinityMessages as $message) {
                    $message->status = 'canceled';
                    $message->save();
                }
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Message group va xabarlar bekor qilindi!'
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Bekor qilishda xatolik yuz berdi: ' . $e->getMessage()
            ], 500);
        }
    }









    public function index(Request $request)
    {
        $user = Auth::user();

        // 1. O‘zimning telefonlarim
        $myPhones = $user->phones()
            ->select('id', 'phone','telegram_user_id', 'session_path', 'created_at', 'updated_at')
            ->latest()
            ->get()
            ->map(function ($phone) use ($user) {
                return $this->formatPhone($phone, true);
            });
        // 2. Boshqalarning telefonlari (faqat superadmin ko‘radi)
        $allPhones = collect();
        if ($user->hasRole('superadmin')) {
            $allPhones = UserPhone::with('user:id,name')
                ->where('user_id', '!=', $user->id)
                ->select('id', 'phone','telegram_user_id', 'user_id',  'created_at')
                ->latest()
                ->get()
                ->map(function ($phone) {
                    return $this->formatPhone($phone, false);
                });
        }

        return response()->json([
            'success' => true,
            'data' => [
                'my_phones' => [
                    'count' => $myPhones->count(),
                    'items' => $myPhones
                ],
                'others_phones' => [
                    'count' => $allPhones->count(),
                    'visible' => $user->hasRole('superadmin'),
                    'items' => $allPhones
                ],
                'stats' => [
                    'total_my'     => $myPhones->count(),
                    'total_others' => $allPhones->count(),
                    'total_all'    => $myPhones->count() + $allPhones->count(),
                ]
            ]
        ]);
    }

    /**
     * GET /api/phones/{id}
     * Bitta telefon ma'lumoti
     */
    public function show(UserPhone $phone)
    {
        $user = Auth::user();

        // Superadmin yoki egasi bo‘lishi kerak
        if ($phone->user_id !== $user->id && !$user->hasRole('superadmin')) {
            return response()->json([
                'success' => false,
                'message' => 'Ruxsat yo‘q'
            ], 403);
        }

        $isOwner = $phone->user_id === $user->id;

        return response()->json([
            'success' => true,
            'data' => $this->formatPhone($phone, $isOwner)
        ]);
    }

    // Telefonni chiroyli formatlash
    private function formatPhone(UserPhone $phone, bool $isOwner = false)
    {
        $owner = $phone->user ?? null;

        return [
            'id'               => $phone->id,
            'phone'            => $phone->phone,
            'telegram_user_id' => $phone->telegram_user_id,
            'is_owner'         => $isOwner,
            'can_control'      => $isOwner || Auth::user()->hasRole('superadmin'),
            'session_exists'   => $isOwner ? file_exists($phone->session_path) : null,
            'created_at'       => $phone->created_at?->toDateTimeString(),
            'created_at_formatted' => $phone->created_at?->format('d.m.Y H:i'),
            'updated_at_formatted' => $phone->updated_at?->format('d.m.Y H:i'),

            'owner' => $owner ? [
                'id'     => $owner->id,
                'name'   => $owner->name,
                'email'  => $owner->email,
                'role'   => $owner->role->name ?? 'user',
            ] : null,
        ];
    }




   public function test()
{
        $catalogs=Catalog::whereNull('timer_minutes')->update(['timer_minutes'=> 10]);
        
        return response()->json([
            'data'=>$catalogs
        ]);
}



    public function sendTelegramMessage(Request $request)
    {
        $validated = $request->validate([
            'phone_id'         => 'nullable|exists:user_phones,id|required_without_all:phone,telegram_user_id',
            'phone'            => 'nullable|exists:user_phones,phone|required_without_all:phone_id,telegram_user_id',
            'telegram_user_id' => 'nullable|exists:user_phones,telegram_user_id|required_without_all:phone_id,phone',
            'catalog_ids'   => 'required|array|min:1',
            'catalog_ids.*' => 'integer|exists:catalogs,id',
            'message'       => 'required|string',
        ]);
        $phone_id = $this->resolvePhoneId($validated, Auth::user());
        if (!$phone_id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Phone not found or not yours.'], 422);
        }
        $peers = $this->peers($validated['catalog_ids']);
        if ($peers->isEmpty()) {
            return back()->withErrors(['peer' => 'Hech qanday peer topilmadi!']);
        }
        $group = MessageGroup::create([
                'user_phone_id' => $phone_id,
                'status' => 'pending',
                'message_text'     => $validated['message'],

        ]);
        if (!empty($validated['catalog_ids'])) {
            $group->catalogs()->sync($validated['catalog_ids']);
        }
        $data = [];
            foreach ($peers as $peer) {
               $data[] = new TelegramMessage([
                    // 'message_text'     => $validated['message'],
                    // 'send_at'=>now(),//single uchun
                    'status'           => 'pending',
                    'peer'             => $peer,
                ]);
            }

        $group->messages()->saveMany($data);
        SendTelegramMessage::dispatch($group)
            ->onQueue('telegram');
        
        // $php = '/opt/php83/bin/php';
        // $artisan = base_path('artisan');
        // $group_id = $group->id;
        // $command = "nohup {$php} {$artisan} telegram:send {$group_id}";
        // exec($command, $output, $return_var);
        // Log::info('Telegram send output: ', ['output' => $output, 'return' => $return_var]);

        return response()->json([
            'status'=>'success'
        ]);

    }






    private function peers($catalog_ids)
    {
        $peers = collect();

        if (!empty($catalog_ids)) {
            $catalogs = Catalog::whereIn('id', $catalog_ids)->get();
            foreach ($catalogs as $catalog) {
                if ($catalog->peers) {
                    $peers = $peers->merge($catalog->peers);
                }
            }
        }

        return $peers = $peers->unique()->values();
    }
    private function resolvePhoneId(array $validated, $user)
    {
        // 1. phone_id orqali
        if (!empty($validated['phone_id'])) {
            $phone = UserPhone::find($validated['phone_id']);

            if (!$phone || (!$user->hasRole('superadmin') && $phone->user_id != $user->id)) {
                return null;
            }

            if (!$this->isValidSession($phone)) {
                return null;
            }

            return $phone->id;
        }

        // 2. phone orqali
        if (!empty($validated['phone'])) {
            $query = UserPhone::where('phone', $validated['phone']);
            if (!$user->hasRole('superadmin')) {
                $query->where('user_id', $user->id);
            }
            $phone = $query->first();

            if (!$phone || !$this->isValidSession($phone)) {
                return null;
            }

            return $phone->id;
        }

        // 3. telegram_user_id orqali
        if (!empty($validated['telegram_user_id'])) {
            $query = UserPhone::where('telegram_user_id', $validated['telegram_user_id']);
            if (!$user->hasRole('superadmin')) {
                $query->where('user_id', $user->id);
            }
            $phone = $query->first();

            if (!$phone || !$this->isValidSession($phone)) {
                return null;
            }

            return $phone->id;
        }

        // Agar hech narsa topilmasa
        return null;
    }


    private function isValidSession(UserPhone $phone)
    {
        return $phone->session_path && file_exists($phone->session_path);
    }

    public function logout($phoneId)
    {
        $phone = UserPhone::findOrFail($phoneId);

        if ($phone->user_id !== Auth::id()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Bu telefon sizga tegishli emas.'
            ], 403);
        }

        if (!$phone->session_path || !file_exists($phone->session_path)) {
            $phone->update(['session_path' => null]);

            return response()->json([
                'status' => 'error',
                'message' => 'Session topilmadi yoki allaqachon log out qilingan.'
            ], 404);
        }

        TelegramLogoutJob::dispatch($phone->id)->onQueue('telegram');

        // $php     = '/opt/php83/bin/php';
        // $artisan = base_path('artisan');
        // $command = "nohup {$php} {$artisan} telegram:logout {$phone->id} >/dev/null 2>&1 &";
        // exec($command);

        return response()->json([
            'status'  => 'processing',
            'message' => 'Logout jarayoni boshlandi'
        ]);
    }
}
