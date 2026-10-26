<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Lang;

/** Human text for an error key stored on a message, an account or an event. */
final class ErrorText
{
    public static function for(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        $seconds = null;
        $base = strtolower($key);
        if (preg_match('/^(.*)_(\d+)$/', $base, $m) === 1) {
            [$base, $seconds] = [$m[1], (int) $m[2]];
        }

        $translationKey = "errors.codes.{$base}";

        return Lang::has($translationKey)
            ? __($translationKey, ['seconds' => $seconds ?? '?', 'time' => $seconds !== null ? self::duration($seconds) : '?'])
            : $key;
    }

    public static function duration(int $seconds): string
    {
        return match (true) {
            $seconds < 60 => __('time.seconds', ['n' => $seconds]),
            $seconds < 3600 => __('time.minutes', ['n' => (int) ceil($seconds / 60)]),
            default => __('time.hours', ['n' => round($seconds / 3600, 1)]),
        };
    }
}
