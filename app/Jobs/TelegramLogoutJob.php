<?php

namespace App\Jobs;

use App\Models\UserPhone;
use danog\MadelineProto\API;
use danog\MadelineProto\Settings\AppInfo;
use danog\MadelineProto\Settings\Logger as LoggerSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class TelegramLogoutJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $phoneId;

    public function __construct(int $phoneId)
    {
        $this->phoneId = $phoneId;
    }

    protected function madeline($sessionPath)
    {
        $settings = new \danog\MadelineProto\Settings();

        $appInfo = new AppInfo();
        $appInfo->setApiId((int) env('TELEGRAM_API_ID'));
        $appInfo->setApiHash(env('TELEGRAM_API_HASH'));
        $settings->setAppInfo($appInfo);

        $loggerSettings = (new LoggerSettings())->setType(\danog\MadelineProto\Logger::ERROR);
        $settings->setLogger($loggerSettings);

        return new API($sessionPath, $settings);
    }

    public function handle(): void
    {
        $userPhone = UserPhone::find($this->phoneId);

        if (!$userPhone) {
            Log::error("UserPhone ID {$this->phoneId} not found for logout");
            return;
        }

        $sessionPath = $userPhone->session_path;

        if (!$sessionPath || !file_exists($sessionPath)) {
            Log::info("Session file for phone ID {$this->phoneId} not found. Cleaning DB record.");
            $userPhone->update(['session_path' => null]);
            return;
        }

        try {
            $Madeline = $this->madeline($sessionPath);
            $Madeline->logOut();
            Log::info("Logged out from Telegram for phone ID {$this->phoneId}");

            if (File::exists($sessionPath)) {
                if (File::isDirectory($sessionPath)) {
                    File::deleteDirectory($sessionPath);
                } else {
                    File::delete($sessionPath);
                }
            }

            $userPhone->update(['session_path' => null]);
            Log::info("✅ Telegram logout completed and session cleared for phone ID {$this->phoneId}");

        } catch (\Throwable $e) {
            Log::error("Logout failed for phone ID {$this->phoneId}: " . $e->getMessage());

            // В любом случае чистим запись и сессию
            $userPhone->update(['session_path' => null]);
            if (File::exists($sessionPath)) {
                if (File::isDirectory($sessionPath)) {
                    File::deleteDirectory($sessionPath);
                } else {
                    File::delete($sessionPath);
                }
            }
        }
    }
}
