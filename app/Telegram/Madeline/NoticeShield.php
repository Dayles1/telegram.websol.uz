<?php

declare(strict_types=1);

namespace App\Telegram\Madeline;

use Illuminate\Support\Facades\Log;

/**
 * Laravel turns every PHP notice into an exception. MadelineProto (and Amp under
 * it) emits harmless notices and deprecations from time to time, which would then
 * fail an operation that actually succeeded. Inside guard(), notices and
 * warnings raised from vendor code are logged instead; anything raised from our
 * own code still goes to Laravel's handler.
 */
final class NoticeShield
{
    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function guard(callable $callback): mixed
    {
        $vendor = base_path('vendor').DIRECTORY_SEPARATOR;
        $previous = null;

        $previous = set_error_handler(function (int $level, string $message, string $file = '', int $line = 0) use (&$previous, $vendor): bool {
            $soft = $level & (E_NOTICE | E_USER_NOTICE | E_WARNING | E_USER_WARNING | E_DEPRECATED | E_USER_DEPRECATED);
            if ($soft && str_starts_with($file, $vendor)) {
                Log::channel('telegram')->debug('Vendor notice ignored', compact('message', 'file', 'line'));

                return true;
            }

            return $previous !== null ? (bool) $previous($level, $message, $file, $line) : false;
        });

        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }
}
