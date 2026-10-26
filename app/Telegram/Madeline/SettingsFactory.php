<?php

declare(strict_types=1);

namespace App\Telegram\Madeline;

use danog\MadelineProto\Logger;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\AppInfo;
use danog\MadelineProto\Settings\Logger as LoggerSettings;
use danog\MadelineProto\Settings\RPC;
use Illuminate\Support\Facades\File;

/**
 * One MadelineProto configuration for every session: the same app credentials
 * and device model (a different one makes Telegram see a new device), no
 * interactive prompts, and the library log in storage/logs/madeline at ERROR level.
 */
final class SettingsFactory
{
    public function make(): Settings
    {
        $logPath = (string) config('telegram.madeline_log');
        File::ensureDirectoryExists(dirname($logPath));

        return (new Settings)
            ->setAppInfo(
                (new AppInfo)
                    ->setApiId((int) config('telegram.api_id'))
                    ->setApiHash((string) config('telegram.api_hash'))
                    ->setDeviceModel((string) config('telegram.device_model'))
                    ->setShowPrompt(false)
            )
            ->setLogger(
                (new LoggerSettings)
                    ->setType(Logger::FILE_LOGGER)
                    ->setExtra($logPath)
                    ->setLevel(Logger::LEVEL_ERROR)
            )
            // Short flood waits are slept off by the library itself; longer ones reach our code.
            ->setRpc((new RPC)->setFloodTimeout(10));
    }
}
