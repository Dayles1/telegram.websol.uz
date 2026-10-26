<?php

namespace App\Helpers;

class PhoneHelper
{
    public static function format(?string $phone): ?string
    {
        if (!$phone) {
            return null;
        }

        $phone = trim($phone);

        $digits = preg_replace('/\D+/', '', $phone);

        if (!$digits) {
            return null;
        }

        if (strlen($digits) < 9 || strlen($digits) > 15) {
            return null;
        }

        if (str_starts_with($phone, '+')) {
            return '+' . $digits;
        }

        

        return '+' . $digits;
    }
}