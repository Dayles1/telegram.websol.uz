<?php

namespace App\Application\Services\Telegram;

class TelegramErrorService
{
    public static function mapErrorToKey(string $err): string
    {
        $e = strtolower($err);

        if (preg_match('/flood[_ ]?wait[_ ]?(\d+)/i', $err, $matches)) {
            $seconds = $matches[1] ?? '';
            return $seconds ? "flood_wait_{$seconds}" : 'flood_wait';
        }

        if (preg_match('/slowmode[_ ]?wait[_ ]?(\d+)/i', $err, $matches)) {
            $seconds = $matches[1] ?? '';
            return $seconds ? "slowmode_wait_{$seconds}" : 'slowmode_wait';
        }

        if (
            str_contains($e, 'schedule_too_much') ||
            str_contains($e, 'schedule too much') ||
            str_contains($e, 'scheduled too many')
        ) {
            return 'SCHEDULE_TOO_MUCH';
        }
        if (
            str_contains($e, 'USERNAME_INVALID') ||
            str_contains($e, 'username invalid') ||
            str_contains($e, 'username_invalid')
        ) {
            return 'username_invalid';
        }
        

        if (
            str_contains($e, 'chat_write_forbidden') ||
            str_contains($e, 'chat write forbidden') ||
            str_contains($e, 'chat admin required')
        ) {
            return 'chat_write_forbidden';
        }

        if (
            str_contains($e, 'channel_invalid') ||
            str_contains($e, 'channel invalid')
        ) {
            return 'chat_write_forbidden';
        }

        if (
            str_contains($e, 'user_banned_in_channel') ||
            str_contains($e, 'user is banned') ||
            str_contains($e, 'user is blocked') ||
            str_contains($e, 'bot was blocked')
        ) {
            return 'user_banned';
        }

        if (
            str_contains($e, 'phone_code_expired') ||
            str_contains($e, 'phone code expired')
        ) {
            return 'phone_code_expired';
        }

        if (
            str_contains($e, 'auth_key_unregistered') ||
            str_contains($e, 'session_revoked') ||
            str_contains($e, 'auth_key_invalid')
        ) {
            return 'auth_key_invalid';
        }

        if (preg_match('/timeout|timed out|connection.*reset|broken pipe|could not connect/i', $e)) {
            return 'network_error';
        }

        if (
            str_contains($e, 'peer is not present in the internal peer database') ||
            str_contains($e, 'peer not found') ||
            str_contains($e, 'peer is not present') ||
            str_contains($e, 'chat not found') ||
            str_contains($e, 'group not found')
        ) {
            return 'peer_not_found';
        }

        if (str_contains($e, 'chat_guest_send_forbidden')) {
            return 'chat_guest_send_forbidden';
        }

        return 'unknown_error';
    }

    public static function getExplanation(string $errorKey): string
    {
        $messages = [
            'username_invalid'=> "Chat noto‘g‘ri yoki mavjud emas.",
            'peer_flood' => "Ushbu chat/foydalanuvchiga yuborishda vaqtincha cheklov mavjud (flood).",
            'phone_migrate' => "Telefon sessiyasi migratsiya qilinmoqda — sozlamalarni tekshiring.",
            'session_password_needed' => "Sessiya paroli talab qilinadi — seans sozlanishi kerak.",
            'network_error' => "Tarmoq xatosi yuz berdi — internet aloqasini tekshiring.",
            'peer_not_found' => "Foydalanuvchi yoki guruh topilmadi — username yoki link noto‘g‘ri bo‘lishi mumkin.",
            'chat_guest_send_forbidden' => "Guruhga xabar yuborish uchun avval guruhga qo‘shiling yoki ruxsat oling.",
            'SCHEDULE_TOO_MUCH' => "Juda ko‘p rejalashtirilgan xabarlar mavjud.",
            'chat_write_forbidden' => "Siz bu yerga yozolmaysiz.",
            'user_banned' => "Siz ushbu chatda bloklangansiz.",
            'phone_code_expired' => "Telefon kodi muddati tugagan.",
            'auth_key_invalid' => "Sessiya yaroqsiz yoki bekor qilingan.",
            'unknown_error' => "Noma'lum xatolik yuz berdi.",
            'not_member'=> 'Guruh a’zosi emas',
            'user_banned_in_channel' => 'Siz ushbu kanal/guruhda bloklangansiz',
        ];

        if (preg_match('/^(flood_wait|slowmode_wait)_(\d+)$/', $errorKey, $matches)) {
            $type = $matches[1];
            $seconds = (int) $matches[2];

            $minutes = intdiv($seconds, 60);
            $remainSeconds = $seconds % 60;

            $timeText = $minutes > 0
                ? "{$minutes} daqiqa" . ($remainSeconds > 0 ? " {$remainSeconds} soniya" : "")
                : "{$remainSeconds} soniya";

            if ($type === 'slowmode_wait') {
                return "Siz bu yerga yozolmaysiz. Yozishga ruxsat {$timeText} dan keyin.";
            }

            return "Juda ko‘p so‘rov yuborildi. {$timeText} dan keyin qayta urinib ko‘ring.";
        }

        return $messages[$errorKey] ?? $messages['unknown_error'];
    }
}