<?php

declare(strict_types=1);

namespace App\Telegram;

/** One phone format everywhere: "+" and digits. */
final class Phone
{
    public const PATTERN = '/^\+?[\d\s\-()]{9,20}$/';

    public static function normalize(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return $digits === '' ? '' : '+'.$digits;
    }

    public static function digits(string $phone): string
    {
        return ltrim(self::normalize($phone), '+');
    }

    /** +998 90 123 45 67 -> +998 90 *** ** 67, for logs and alerts. */
    public static function mask(string $phone): string
    {
        $digits = self::digits($phone);

        return strlen($digits) < 7 ? $phone : '+'.substr($digits, 0, 5).str_repeat('*', strlen($digits) - 7).substr($digits, -2);
    }
}
