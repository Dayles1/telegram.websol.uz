<?php

namespace App\Application\Services;

use danog\MadelineProto\API;
use App\Models\UserPhone;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

class MadelineService
{
    protected ?API $madeline = null;

    /**
     * Madeline session start va validatsiyasi
     *
     * @param UserPhone $userPhone
     * @return bool true — session ishlaydi, false — reset yoki xatolik
     */
    public function validateAndStart(UserPhone $userPhone): bool
    {
        $path = $userPhone->session_path;

        if (!$path || !File::exists($path)) {
            Log::warning("❌ Session yo‘q yoki diskda topilmadi: user_phone_id={$userPhone->id}");
            $userPhone->update(['session_path' => null, 'is_active' => false]);
            return false;
        }

        try {
            $this->madeline = new API($path);
            $this->madeline->start();
            return true;
        } catch (Throwable $e) {
            Log::error("❌ Madeline start failed: user_phone_id={$userPhone->id}, error={$e->getMessage()}");

            $msg = $e->getMessage();
            $shouldReset =
                str_contains($msg, 'AUTH_KEY_UNREGISTERED') ||
                str_contains($msg, 'SESSION_REVOKED') ||
                str_contains($msg, 'AUTH_KEY_INVALID');

            if ($shouldReset) {
                Log::warning("🔄 Session RESET qilinmoqda: user_phone_id={$userPhone->id}");
                if (File::exists($path)) {
                    File::isDirectory($path) ? File::deleteDirectory($path) : File::delete($path);
                }

                $userPhone->update(['session_path' => null, 'is_active' => false]);
            }

            return false;
        }
    }

    /**
     * API obyekti olish
     *
     * @return API|null
     */
    public function getApi(): ?API
    {
        return $this->madeline;
    }
}
