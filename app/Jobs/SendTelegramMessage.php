<?php

namespace App\Jobs;

use App\Models\UserPhone;
use danog\MadelineProto\API;
use Illuminate\Bus\Queueable;
use danog\MadelineProto\Logger;
use danog\MadelineProto\Settings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use danog\MadelineProto\Settings\Logger as LoggerSettings;

class SendTelegramMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // public $tries = 5;
    // public $backoff = [60, 300, 600];

    public function __construct(protected $group) {}
    // public function handle()
    // {
    //     $messages = $this->group->messages()->where('status', 'pending')->get();
    //     $userPhone = UserPhone::find($this->group->user_phone_id);

    //     if (!$userPhone || !file_exists($userPhone->session_path)) {
    //         $path = $userPhone->session_path;

    //         if (File::exists($path) && File::isDirectory($path)) {
    //             File::deleteDirectory($path);
    //         }
    //         Log::warning("❌ Session topilmadi: user_phone_id={$this->group->user_phone_id}");

    //         $this->group->messages()->where('status', 'pending')
    //             ->update(['status' => 'failed']);

    //         return; 
    //     }

    //     try {
    //         // $settings = new Settings;
    //         // $settings->getAppInfo()
    //         //     ->setApiId(env('TELEGRAM_API_ID'))
    //         //     ->setApiHash(env('TELEGRAM_API_HASH'));

    //         // $loggerSettings = (new LoggerSettings)
    //         //     ->setType(Logger::FILE_LOGGER);

    //         // $settings->setLogger($loggerSettings);

    //         // $Madeline = new API($userPhone->session_path, $settings);
    //         $Madeline = new API($userPhone->session_path); // settings YO‘Q
    //         $Madeline->start();
    //     } catch (\Throwable $e) {

    //         Log::error("❌ MadelineProto start failed for user_phone_id={$this->group->user_phone_id}", [
    //             'error' => $e->getMessage(),
    //         ]);

    //         $msg = $e->getMessage();

    //         $shouldReset =
    //             str_contains($msg, 'AUTH_KEY_UNREGISTERED') ||
    //             str_contains($msg, 'SESSION_REVOKED') ||
    //             str_contains($msg, 'AUTH_KEY_INVALID');

    //         if ($shouldReset) {
    //             Log::warning("🔄 Session RESET qilinmoqda: user_phone_id={$userPhone->id}");

    //             $path = $userPhone->session_path;

    //             if (File::exists($path) && File::isDirectory($path)) {
    //                 File::deleteDirectory($path);
    //             }

    //             $userPhone->update(['session_path' => null]);

    //             $this->group->messages()->where('status', 'pending')
    //                 ->update(['status' => 'failed']);
    //         }

    //         return;
    //     }

    //     foreach ($messages as $msg) {
    //         try {
    //             $Madeline->messages->sendMessage([
    //                 'peer'    => $msg->peer,
    //                 'message' => $msg->message_text,
    //                 'parse_mode' => 'HTML'
    //             ]);

    //             $msg->update([
    //                 'status'   => 'sent',
    //                 'sent_at'  => now(),
    //                 'attempts' => 0,
    //             ]);
    //         } catch (\Throwable $e) {
    //             Log::error("Telegram send failed for peer {$msg->peer}", [
    //                 'error' => $e->getMessage(),
    //             ]);
    //             $msg->update(['status' => 'failed']);
    //             continue;
    //         }
    //     }
    // }

    public function handle()
    {
        $groupId=$this->group->id;
        $php = '/opt/php83/bin/php';
            $artisan = base_path('artisan');
            $command = "nohup {$php} {$artisan} telegram:send-messages {$groupId} > /dev/null 2>&1 &";
            exec($command);
    }
}
