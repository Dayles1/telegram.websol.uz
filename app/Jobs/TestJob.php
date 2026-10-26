<?php

namespace App\Jobs;

use App\Models\UserPhone;
use danog\MadelineProto\API;
use danog\MadelineProto\Logger;
use danog\MadelineProto\Settings;
use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use danog\MadelineProto\Settings\Logger as LoggerSettings;

class TestJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        //
    }

    public function handle(): void
    {
        try {

            $sessionPath = UserPhone::find(7)->session_path;

            $settings = new Settings;
            $settings->getAppInfo()
                ->setApiId(env('TELEGRAM_API_ID'))
                ->setApiHash(env('TELEGRAM_API_HASH'));

            $loggerSettings = (new LoggerSettings)
                ->setType(Logger::FILE_LOGGER);

            $settings->setLogger($loggerSettings);

            // Madeline instance
            $Madeline = new API($sessionPath, $settings);

            $Madeline->start();

            $chatId = 'quinn_talen'; 

            try {
                // Chatdan oxirgi xabarlarni olish
                $messages = $Madeline->messages->getHistory([
                    'peer' => $chatId,
                    'limit' => 10, // Oxirgi 10 xabar
                ]);
                Log::info("Successfully retrieved messages from chat ID: {$chatId}::: " . json_encode($messages,JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                // foreach ($messages['messages'] as $msg) {
                //     $text = $msg['message'] ?? '';
                //     $date = date('Y-m-d H:i:s', $msg['date']);

                //     $fromId = 'unknown';
                //     if (isset($msg['from_id'])) {
                //         if (isset($msg['from_id']['user_id'])) {
                //             $fromId = $msg['from_id']['user_id'];
                //         } elseif (isset($msg['from_id']['channel_id'])) {
                //             $fromId = 'channel_' . $msg['from_id']['channel_id'];
                //         } elseif (isset($msg['from_id']['chat_id'])) {
                //             $fromId = 'chat_' . $msg['from_id']['chat_id'];
                //         }
                //     }

                //     Log::info("CHAT MESSAGE → Chat ID: {$chatId}, From: {$fromId}, Date: {$date}, Text: {$text}");
                // }
            } catch (\Throwable $e) {
                Log::error("ERROR GETTING CHAT MESSAGES: " . $e->getMessage());
            }
        } catch (\Throwable $e) {
            Log::error("TEST JOB ERROR: " . $e->getMessage());
        }
    }
}
