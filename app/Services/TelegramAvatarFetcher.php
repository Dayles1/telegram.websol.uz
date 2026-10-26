<?php
namespace App\Services;

use Throwable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use danog\MadelineProto\API;
use App\Models\UserPhone;

class TelegramAvatarFetcher
{
    protected string $avatarsDir = 'avatars'; // storage/app/public/avatars

    /**
     * Fetch avatar by phone id.
     * Returns array: ['success' => bool, 'message' => string, 'path' => string|null, 'url' => string|null]
     */
    public function fetchByPhoneId(int $phoneId): array
    {
        $phone = UserPhone::find($phoneId);
        if (!$phone) {
            return ['success' => false, 'message' => "Phone id {$phoneId} not found.", 'path' => null, 'url' => null];
        }

        if (!$phone->session_path || !File::exists($phone->session_path)) {
            return ['success' => false, 'message' => "Session file not found for phone id {$phoneId}.", 'path' => null, 'url' => null];
        }

        // ensure public/avatars exists
        if (!Storage::disk('public')->exists($this->avatarsDir)) {
            Storage::disk('public')->makeDirectory($this->avatarsDir);
        }

        try {
            $api = new API($phone->session_path);
            $api->start();

            $me = $api->getSelf();
            if (!$me || empty($me['id'])) {
                return ['success' => false, 'message' => "Could not get self/user id from Madeline for phone id {$phoneId}.", 'path' => null, 'url' => null];
            }

            $telegramUserId = $me['id'];
            $photos = $api->photos->getUserPhotos(['user_id' => $telegramUserId, 'limit' => 1]);

            if (empty($photos) || empty($photos['photos'])) {
                return ['success' => false, 'message' => "No profile photo for this Telegram account.", 'path' => null, 'url' => null];
            }

            $photo = $photos['photos'][0];
            $downloadResult = $api->downloadToDir($photo, storage_path('app/public/' . $this->avatarsDir));
            $filePath = is_array($downloadResult) ? reset($downloadResult) : $downloadResult;

            if (!$filePath || !File::exists($filePath)) {
                return ['success' => false, 'message' => "Download failed or file not found after download.", 'path' => null, 'url' => null];
            }

            $basename = basename($filePath);
            $relative = $this->avatarsDir . '/' . $basename; // e.g. avatars/filename.jpg

            $user = $phone->user;
            if (!$user) {
                return ['success' => false, 'message' => "Phone id {$phoneId} is not linked to any user.", 'path' => null, 'url' => null];
            }

            // delete old avatar file if exists
            if ($user->avatar()->exists()) {
                $old = $user->avatar;
                if (!empty($old->path)) {
                    Storage::disk('public')->delete($old->path);
                }
                $user->avatar()->update(['path' => $relative]);
            } else {
                $user->avatar()->create(['path' => $relative]);
            }

            $url = Storage::disk('public')->url($relative);

            return ['success' => true, 'message' => 'Avatar downloaded', 'path' => $relative, 'url' => $url];

        } catch (Throwable $e) {
            $msg = $e->getMessage();
            Log::error("Madeline error for phone id {$phoneId}", ['error' => $msg]);

            $shouldReset = str_contains($msg, 'AUTH_KEY_UNREGISTERED')
                || str_contains($msg, 'SESSION_REVOKED')
                || str_contains($msg, 'AUTH_KEY_INVALID');

            if ($shouldReset) {
                try {
                    if (File::exists($phone->session_path)) {
                        File::isDirectory($phone->session_path)
                            ? File::deleteDirectory($phone->session_path)
                            : File::delete($phone->session_path);
                    }
                } catch (Throwable $ex) {
                    Log::warning("Could not delete session file: {$phone->session_path}", ['error' => $ex->getMessage()]);
                }

                $phone->update(['session_path' => null]);
                Log::warning("Session reset for phone id={$phoneId}");
            }

            return ['success' => false, 'message' => 'Madeline error: ' . $msg, 'path' => null, 'url' => null];
        }
    }
}
