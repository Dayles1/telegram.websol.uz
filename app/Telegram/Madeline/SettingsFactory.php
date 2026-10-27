<?php

declare(strict_types=1);

namespace App\Telegram\Madeline;

use App\Telegram\Phone;
use danog\MadelineProto\Logger;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\AppInfo;
use danog\MadelineProto\Settings\Logger as LoggerSettings;
use danog\MadelineProto\Settings\RPC;
use Illuminate\Support\Facades\File;

/**
 * One MadelineProto configuration for every session: the same app credentials
 * and device model (a different one makes Telegram see a new device), the
 * language of the phone's country, no interactive prompts, and the library log
 * in storage/logs/madeline at ERROR level.
 */
final class SettingsFactory
{
    public function make(?string $phone = null): Settings
    {
        $logPath = (string) config('telegram.madeline_log');
        File::ensureDirectoryExists(dirname($logPath));
        $language = self::languageOf($phone);

        return (new Settings)
            ->setAppInfo(
                (new AppInfo)
                    ->setApiId((int) config('telegram.api_id'))
                    ->setApiHash((string) config('telegram.api_hash'))
                    ->setDeviceModel((string) config('telegram.device_model'))
                    // always set: Madeline otherwise takes it from the process's LANG ("C.UTF-8" under supervisor)
                    ->setLangCode($language)
                    ->setSystemLangCode($language)
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

    /** telegram.languages: the longest matching country code, else the default. */
    public static function languageOf(?string $phone): string
    {
        $digits = $phone === null ? '' : Phone::digits($phone);
        $prefixes = (array) config('telegram.languages.by_phone_prefix');
        uksort($prefixes, fn ($a, $b) => strlen((string) $b) <=> strlen((string) $a));
        foreach ($prefixes as $prefix => $language) {
            if ($digits !== '' && str_starts_with($digits, (string) $prefix)) {
                return (string) $language;
            }
        }

        return (string) config('telegram.languages.default');
    }
}
