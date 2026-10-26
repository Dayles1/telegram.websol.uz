<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Peer;
use App\Models\Catalog;
use App\Models\UserPhone;
use App\Models\MessageGroup;
use danog\MadelineProto\API;
use Illuminate\Http\Request;
use App\Jobs\TelegramAuthJob;
use App\Jobs\CompleteLoginJob;
use App\Jobs\TelegramVerifyJob;
use App\Models\TelegramMessage;
use App\Jobs\SendTelegramMessage;
use danog\MadelineProto\Settings;
use Illuminate\Support\Facades\DB;
use App\Models\TelegramAuthSession;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use App\Services\CatalogTimerService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use danog\MadelineProto\Settings\AppInfo;
use Illuminate\Support\Facades\Validator;
use App\Domain\Telegram\Services\TelegramSessionService;
use App\Domain\Telegram\Services\TelegegramSendMessageService;

class TelegramController extends Controller
{
    public function authRequest(Request $r)
    {
        $phone = $r->phone;
        $user = Auth::user();
        $userId = $user->id;

        
        // Otherwise create the session (or update existing to pending)
        $session = \App\Models\TelegramAuthSession::updateOrCreate(
            [
                'user_id' => $userId,
                'phone' => $phone,
            ],
            [
                'status' => 'pending',
                'message_key' => 'wait',
                'message' => null,
                'attempts' => 0
            ]
        );
        $sessionPath = storage_path("app/sessions/{$phone}_user_{$userId}.madeline");
        if (File::exists($sessionPath)) {
            File::deleteDirectory($sessionPath);
            Log::info("Old session folder deleted for {$phone} and user ID {$userId}");
        }

        Log::info("Starting Telegram auth for {$phone} and user ID {$userId}");

        TelegramAuthJob::dispatch($phone, $userId, $session->id)->onQueue('telegram');

        return response()->json([
            'status' => 'pending',
            'session_id' => $session->id,
            'message_key' => $session->message_key
        ]);
    }

    // 2) Verify code: only dispatch if session exists and we have sms_sent state
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
        $userId = Auth::id();

        $session = TelegramAuthSession::where('user_id', $userId)->where('phone', $phone)->first();

        if (!$session) {
            return response()->json(['status' => 'error', 'message' => 'session_not_found'], 404);
        }

        // Allow dispatch only if session is sms_sent (so we don't spam the job)
        if (!in_array($session->status, ['sms_sent', 'code_sent'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'wait_for_sms',
                'session_status' => $session->status
            ], 409);
        }

        // increment attempts and mark code_sent (frontend will show loader until job updates session)
        $session->increment('attempts');
        $session->update(['status' => 'processing', 'message' => null, 'message_key' => 'processing', 'last_ping' => now()]);

        TelegramVerifyJob::dispatch($phone, $userId, $r->code, $session->id)->onQueue('telegram');

        return response()->json([
            'status' => 'processing',
            'session_id' => $session->id,
            'message_key' => 'processing',
            'message' => null
        ]);
    }

    // 3) Complete login (2FA password)
    public function completeLogin(Request $r)
    {
        $validator = Validator::make($r->all(), [
            'phone'    => 'required|string|min:9|max:20',
            'password' => 'nullable|string|min:4|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'validation_error',
                'errors' => $validator->errors()
            ], 422);
        }

        $phone = $r->phone;
        $password = $r->password;
        $userId = Auth::id();

        $session = TelegramAuthSession::where('phone', $phone)->where('user_id', $userId)->first();

        if (!$session) {
            return response()->json(['status' => 'error', 'message' => 'session_not_found'], 404);
        }

        // allow password only if we are in need_password
        if ($session->status !== 'need_password') {
            return response()->json([
                'status' => 'error',
                'message' => 'not_waiting_password',
                'session_status' => $session->status
            ], 409);
        }

        $session->update(['status' => 'processing', 'last_ping' => now(), 'message_key' => 'processing', 'message' => null]);

        CompleteLoginJob::dispatch($phone, $userId, $password, $session->id)->onQueue('telegram');

        return response()->json([
            'status' => 'processing',
            'session_id' => $session->id,
            'message_key' => 'processing'
        ]);
    }

    // 4) Status: frontend polls this periodically
    public function status(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|min:9|max:20'
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 'validation_error', 'errors' => $validator->errors()], 422);
        }

        $session = TelegramAuthSession::where('phone', $request->phone)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        $session->update(['last_ping' => now()]);

        return response()->json([
            'status' => $session->status,
            'message' => $session->message,
            'message_key' => $session->message_key
        ]);
    }






    public function sendMessage(Request $r)
    {
        $r->validate([
            'phone'   => 'required|string|exists:user_phones,phone',
            'user_id' => 'required|string',
            'message' => 'required|string|max:4096',
        ]);

        $phone   = $r->phone;
        $peer  = $r->user_id;
        $message = $r->message;
        $userId  = Auth::user()->id;

        $php     = '/opt/php83/bin/php';
        $artisan = base_path('artisan');
        Log::info("Dispatching Telegram send message from {$phone} and to  {$peer}");

        // $command = "nohup {$php} {$artisan} telegram:send-pending > /dev/null 2>&1 &";
        $command = "nohup {$php} {$artisan} telegram:send {$phone} {$userId} {$peer} \"{$message}\" > /dev/null 2>&1 &";

        exec($command);

        return response()->json(['status' => 'message_sent_in_background']);
    }


    public function sendMassMessage(Request $request, CatalogTimerService $timerService)
    {
        $validated = $request->validate([
            'phone_id'         => 'nullable|exists:user_phones,id|required_without_all:phone,telegram_user_id',
            'phone'            => 'nullable|exists:user_phones,phone|required_without_all:phone_id,telegram_user_id',
            'telegram_user_id' => 'nullable|exists:user_phones,telegram_user_id|required_without_all:phone_id,phone',
            'catalog_ids'      => 'required|array|min:1',
            'catalog_ids.*'    => 'integer|exists:catalogs,id',
            'message'          => 'required|string',
        ]);

        $phone_id = $this->resolvePhoneId($validated, Auth::user());

        if (!$phone_id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Phone not found or not yours.'
            ], 422);
        }

        $peers = $this->peers($validated['catalog_ids']);
        if ($peers->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Hech qanday peer topilmadi!',
            ], 422);
        }



        $reserve = $timerService->reserveCatalogsForPhone($validated['catalog_ids'], $phone_id, Auth::id());

        if (! $reserve['allowed']) {
            $messages = [];
            foreach ($reserve['blocked'] as $info) {
                $catalogName = $info['name'] ?? 'Catalog';
                if ($info['reason'] === 'cooldown') {
                    $sec = $info['seconds_left'] ?? 0;
                    $min = intdiv($sec, 60);
                    $srem = $sec % 60;
                    $messages[] = "$catalogName - cooldown: {$min}m {$srem}s qolgan.";
                } elseif ($info['reason'] === 'no_permission') {
                    $messages[] = "$catalogName - sizda ishlatish huquqi yo'q.";
                } elseif ($info['reason'] === 'not_found') {
                    $messages[] = "$catalogName - topilmadi.";
                } else {
                    $messages[] = "$catalogName - vaqtinchalik xatolik yoki block. Iltimos keyinroq urinib ko‘ring.";
                }
            }

            return response()->json([
                'status' => 'error',
                'message' => implode(' ', $messages),
                'blocked' => $reserve['blocked'],
            ], 429);
        }

        $group = MessageGroup::create([
            'user_phone_id' => $phone_id,
            'status'        => 'pending',
            'message_text'  => $validated['message'],
        ]);

        if (! empty($validated['catalog_ids'])) {
            $group->catalogs()->sync($validated['catalog_ids']);
        }

        $messages = [];
        foreach ($peers as $peer) {
            $messages[] = new TelegramMessage([
                'status' => 'pending',
                'peer'   => $peer,
            ]);
        }

        $group->messages()->saveMany($messages);

        SendTelegramMessage::dispatch($group)->onQueue('telegram');

        return response()->json([
            'status'  => 'success',
            'message' => 'Xabarlar navbatga qo‘shildi!'
        ]);
    }



    // █░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░▓
    // ░                                        ░
    // ░                                        ░
    // ░                                 ░▒░    ░
    // ░                ░░▒▒▒▒▒░░░     ░█░      ░
    // ░            ░▒████████████▓░ ▒██░       ░
    // ░          ░████▓░         ░▓███░        ░
    // ░         ░███▒          ░▓█████░        ░
    // ░         ███░         ░██▒  ▓███░       ░
    // ░        ░███░       ▒█▒      ███░       ░
    // ░         ███░     ▒░░       ▒███░       ░
    // ░         ▒███░             ░███░        ░
    // ░         ░███▓░         ░▒████░         ░
    // ░         ▓█░ ░▒▓████████████░           ░
    // ░       ▒▒░     ░░▒█████▒░░              ░
    // ░     ▒░                                 ░
    // ░                                        ░
    // ░                                        ░
    // █░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░▓
























    public function showPhoneForm()
    {
        return view('telegram.phone');
    }
    public function showSendForm(Request $r)
    {
        $phone = $r->phone;
        return view('telegram.send', compact('phone'));
    }
    public function showSendMessagesForm(Request $r)
    {
        $user = Auth::user();

        if ($user->hasRole('superadmin')) {
            $userPhones = UserPhone::with('user')->whereNotNull('session_path')->get();
            $ownPhones = $userPhones->where('user_id', $user->id);
            $otherPhones = $userPhones->where('user_id', '!=', $user->id);
        } else {
            $ownPhones = $user->phones()->get();
            $otherPhones = [];
        }

        // 🟢 User cataloglari
        $catalogs = $user->catalogs()->get();

        // 🔵 Global cataloglar (misol: is_global = 1)
        // $globalCatalogs = Catalog::where('is_global', 1)->get();
$globalCatalogs = collect(); // bu bo‘sh Collection

        return view(
            'telegram.send-mass',
            compact('ownPhones', 'otherPhones', 'catalogs', 'globalCatalogs')
        );
    }




    public function test()
    {
        //     $tables = DB::select('SHOW TABLES');
        // $dbName = env('DB_DATABASE');
        //     DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        // foreach ($tables as $table) {
        //     $tableName = $table->{'Tables_in_' . $dbName}; // MySQL uchun
        //     Schema::dropIfExists($tableName);
        // }
        // DB::statement('SET FOREIGN_KEY_CHECKS=1;');


        // return response()->json(['status' => 'Database cleared']);


        $time = now();
        $t = TelegramMessage::first();
        dd($time);
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
        //return $phone->session_path && file_exists($phone->session_path);
        return true;
    }
}
