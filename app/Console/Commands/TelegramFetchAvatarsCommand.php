<?php

namespace App\Console\Commands;

use Throwable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use danog\MadelineProto\API;
use App\Models\User;
use App\Models\UserPhone;
use App\Models\Image;

class TelegramFetchAvatarsCommand extends Command
{
    protected $signature = 'telegram:fetch-avatars';
    protected $description = 'For each user without an avatar: start Madeline session (by phone), fetch profile photo and save it (first found).';

    // Where to store downloaded avatars (storage/app/public/avatars)
    protected string $avatarsDir = 'public/avatars';

    public function handle()
    {
        $this->info('✨ Starting avatar fetch...');

        // Ensure storage dir exists
        $diskDir = storage_path('app/' . trim($this->avatarsDir, 'public/'));
        if (!File::exists(storage_path('app/public'))) {
            File::makeDirectory(storage_path('app/public'), 0755, true);
        }
        $fullDir = storage_path('app/public/avatars');
        if (!File::exists($fullDir)) {
            File::makeDirectory($fullDir, 0755, true);
        }

        User::chunk(100, function ($users) {
            foreach ($users as $user) {
                if ($user->avatar()->exists()) {
                    $this->info("skip: user {$user->id} already has avatar");
                    continue;
                }

                $this->info("processing user {$user->id} ({$user->email}?)...");

                // Only phones with session_path
                $phones = $user->phones()->whereNotNull('session_path')->get();

                $saved = false;

                foreach ($phones as $phone) {
                    if (!$phone->session_path || !File::exists($phone->session_path)) {
                        Log::warning("Session file missing for user_phone_id={$phone->id}");
                        continue;
                    }

                    try {
                        $api = new API($phone->session_path);
                        $api->start();

                        $me = $api->getSelf();
                        if (!$me) {
                            Log::warning("getSelf returned falsy for user_phone_id={$phone->id}");
                            continue;
                        }

                        $userId = $me['id'] ?? null;
                        if (!$userId) {
                            Log::warning("No user id from getSelf for user_phone_id={$phone->id}");
                            continue;
                        }

                        $photos = $api->photos->getUserPhotos(['user_id' => $userId, 'limit' => 1]);
                        if (empty($photos) || empty($photos['photos'])) {
                            $this->info("no profile photo for user {$user->id} using phone {$phone->phone}");
                            continue;
                        }

                        $photo = $photos['photos'][0];
                        $downloadResult = $api->downloadToDir($photo, storage_path('app/public/avatars'));

                        $filePath = is_array($downloadResult) ? reset($downloadResult) : $downloadResult;

                        if (!$filePath || !File::exists($filePath)) {
                            Log::error("download failed or file not found after download for user_phone_id={$phone->id}");
                            continue;
                        }

                        $relative = 'avatars/' . basename($filePath);
                        $user->avatar()->create(['path' => $relative]);

                        $this->info("✅ Avatar saved for user {$user->id} -> {$relative}");
                        $saved = true;
                        break;

                    } catch (Throwable $e) {
                        $msg = $e->getMessage();
                        Log::error("Madeline start/getSelf/download failed for user_phone_id={$phone->id}", ['error' => $msg]);

                        $shouldReset = str_contains($msg, 'AUTH_KEY_UNREGISTERED')
                            || str_contains($msg, 'SESSION_REVOKED')
                            || str_contains($msg, 'AUTH_KEY_INVALID');

                        if ($shouldReset) {
                            try {
                                if (File::exists($phone->session_path)) {
                                    File::isDirectory($phone->session_path) ? File::deleteDirectory($phone->session_path) : File::delete($phone->session_path);
                                }
                            } catch (Throwable $ex) {
                                Log::warning("Could not delete session file: {$phone->session_path}", ['error' => $ex->getMessage()]);
                            }

                            $phone->update(['session_path' => null]);
                            Log::warning("Session reset for user_phone_id={$phone->id}");
                        }

                        continue;
                    }
                }

                if (!$saved) {
                    $this->info("no avatar found for user {$user->id}");
                }
            }
        });

        $this->info('✨ Done.');
        return 0;
    }
}
