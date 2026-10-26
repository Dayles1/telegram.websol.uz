<?php

namespace App\Jobs;

use danog\MadelineProto\API;
use Illuminate\Bus\Queueable;
use danog\MadelineProto\Settings;
use App\Models\TelegramAuthSession;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class TelegramAuthJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $phone;
    public $userId;
    public $sessionId;

    /**
     * Create a new job instance.
     */
    public function __construct(string $phone, int $userId, int $sessionId)
    {
        $this->phone = $phone;
        $this->userId = $userId;
        $this->sessionId = $sessionId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $phone = $this->phone;
        $userId = $this->userId;

        Log::info("TelegramAuthJob started for {$phone}, user {$userId}");

        $sessionPath = storage_path("app/sessions/{$phone}_user_{$userId}.madeline");

        // Удаляем старую сессию
        if (file_exists($sessionPath)) {
            if (is_dir($sessionPath)) {
                File::deleteDirectory($sessionPath);
            } else {
                unlink($sessionPath);
            }
            sleep(3);
        }

        if (!is_dir(dirname($sessionPath))) {
            mkdir(dirname($sessionPath), 0777, true);
        }

        $settings = new Settings;
        $settings->setAppInfo(
            (new \danog\MadelineProto\Settings\AppInfo)
                ->setApiId(env('TELEGRAM_API_ID'))
                ->setApiHash(env('TELEGRAM_API_HASH'))
        );
        $session = TelegramAuthSession::find($this->sessionId);
        $session->update(['status' => 'processing', 'last_ping' => now()]);
        try {
            $Madeline = new API($sessionPath, $settings);
            
            $Madeline->phoneLogin($phone);
            $session->update(['status' => 'sms_sent','message_key'=>'sms_sent']);
            Log::info("SMS code sent successfully to {$phone}");
        } catch (\Exception $e) {
            Log::error("TelegramAuthJob error for {$phone}: " . $e->getMessage());
            $session->update(['status' => 'failed', 'message' => $e->getMessage(),'message_key'=>null]);
        }
    }
}
