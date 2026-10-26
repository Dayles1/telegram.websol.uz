<?php

namespace App\Jobs;

use Carbon\Carbon;
use App\Models\ChatLog;
use App\Models\UserPhone;
use Illuminate\Support\Str;
use App\Models\ChatLogGroup;
use danog\MadelineProto\API;
use Illuminate\Bus\Queueable;
use danog\MadelineProto\Logger;
use danog\MadelineProto\Settings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class FetchChatMessagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $userPhoneId;
    protected string $peer;
    protected int $limit;

    public function __construct(int $userPhoneId, string $peer, int $limit = 100)
    {
        $this->userPhoneId = $userPhoneId;
        $this->peer = $peer;
        $this->limit = $limit;
    }

    public function handle(): void
    {
        try {
            $phone = UserPhone::findOrFail($this->userPhoneId);

            try {
                $Madeline = new API($phone->session_path);
                $Madeline->start();
            } catch (\Throwable $e) {
                Log::error("❌ MadelineProto start failed for user_phone_id={$this->userPhoneId}", [
                    'error' => $e->getMessage(),
                ]);
                $msg = $e->getMessage();
                $shouldReset =
                    str_contains($msg, 'AUTH_KEY_UNREGISTERED') ||
                    str_contains($msg, 'SESSION_REVOKED') ||
                    str_contains($msg, 'AUTH_KEY_INVALID');
                if ($shouldReset) {
                    $path = $phone->session_path;
                    if (File::exists($path) && File::isDirectory($path)) {
                        File::deleteDirectory($path);
                    }
                    $phone->update(['session_path' => null]);
                }
                return;
            }

            try {
                $messages = $Madeline->messages->getHistory([
                    'peer' => $this->peer,
                    'limit' => $this->limit,
                ]);

                // Build lookup maps for users and chats (id => username/title)
                $usersMap = [];
                if (!empty($messages['users']) && is_array($messages['users'])) {
                    foreach ($messages['users'] as $u) {
                        if (!empty($u['id'])) {
                            $usersMap[$u['id']] = $u['username'] ?? ($u['first_name'] ?? null);
                        }
                    }
                }

                $chatsMap = [];
                if (!empty($messages['chats']) && is_array($messages['chats'])) {
                    foreach ($messages['chats'] as $c) {
                        if (!empty($c['id'])) {
                            $chatsMap[$c['id']] = $c['title'] ?? null;
                        }
                    }
                }

                $myTelegramId = $phone->telegram_user_id ?? $phone->telegram_id ?? null;
                $myUsername = $phone->telegram_username ?? $phone->username ?? null;

                foreach ($messages['messages'] as $msg) {
                    $messageId = $msg['id'] ?? null;
                    if (!$messageId) continue;

                    // ---------- Robust from_id / username resolution ----------
                    $fromUserId = null;
                    $fromUsername = null;

                    if (isset($msg['from_id'])) {
                        $fid = $msg['from_id'];
                        if (is_array($fid)) {
                            if (isset($fid['user_id'])) {
                                $fromUserId = $fid['user_id'];
                            } elseif (isset($fid['channel_id'])) {
                                $chan = $fid['channel_id'];
                                $fromUsername = $chatsMap[$chan] ?? 'Unknown';
                            } elseif (isset($fid['chat_id'])) {
                                $chatid = $fid['chat_id'];
                                $fromUsername = $chatsMap[$chatid] ?? 'Unknown';
                            }
                        } else {
                            $fromUserId = $fid;
                        }
                    }

                    if ($fromUserId === null && isset($msg['peer_id'])) {
                        $peerIdRaw = $msg['peer_id'];
                        if (is_array($peerIdRaw)) {
                            if (isset($peerIdRaw['user_id'])) {
                                $fromUserId = $peerIdRaw['user_id'];
                            } elseif (isset($peerIdRaw['channel_id'])) {
                                $fromUsername = $chatsMap[$peerIdRaw['channel_id']] ?? null;
                            }
                        } else {
                            if (is_int($peerIdRaw) || ctype_digit((string)$peerIdRaw)) {
                                $pid = (int)$peerIdRaw;
                                if ($pid > 0) {
                                    $fromUserId = $pid;
                                } else {
                                    $fromUsername = $chatsMap[abs($pid)] ?? null;
                                }
                            }
                        }
                    }

                    if ($fromUserId !== null && isset($usersMap[$fromUserId])) {
                        $fromUsername = $usersMap[$fromUserId];
                    }

                    $peerNumeric = null;
                    if (is_numeric($this->peer)) {
                        $peerNumeric = (int)$this->peer;
                    } elseif (is_string($this->peer) && ctype_digit(ltrim($this->peer, '-'))) {
                        $peerNumeric = (int)$this->peer;
                    }
                    if ($fromUsername === null) {
                        if ($fromUserId !== null && $myTelegramId !== null && ((int)$fromUserId === (int)$myTelegramId)) {
                            $fromUsername = $myUsername ?? 'Me';
                        } else {
                            if ($peerNumeric !== null && $peerNumeric < 0) {
                                $fromUsername = 'Unknown';
                            } else {
                                $fromUsername = $usersMap[$fromUserId] ?? 'Unknown';
                            }
                        }
                    }

                    // ---------- GROUP CREATE / UPDATE ----------
                    $group = ChatLogGroup::updateOrCreate(
                        [
                            'user_phone_id' => $phone->id,
                            'peer' => $this->peer,
                        ],
                        [
                            'chat_type' => $msg['_'] ?? null,
                        ]
                    );

                    // ---------- INCOMING TEXT (caption/message) ----------
                    $incomingText = $msg['message'] ?? null;
                    if (isset($msg['caption'])) {
                        $incomingText = $msg['caption'];
                    } elseif (isset($msg['media']['caption'])) {
                        $incomingText = $msg['media']['caption'];
                    }
                    $incomingNormalized = trim((string)($incomingText ?? ''));

                    // ---------- Get existing latest record early (to avoid re-downloading media unnecessarily) ----------
                    $existingLatest = ChatLog::where('chat_log_group_id', $group->id)
                        ->where('message_id', $messageId)
                        ->orderBy('id', 'desc')
                        ->first();

                    // ---------- media detection (prepare meta) ----------
                    $mediaType = null;
                    $mediaFileName = null;
                    $mediaMime = null;
                    $mediaSize = null;
                    $mediaLocalPath = null;
                    $thumbLocalPath = null;
                    $providerFileId = null;

                    $mimeMap = function ($mime) {
                        static $map = [
                            'image/jpeg' => 'jpg',
                            'image/jpg' => 'jpg',
                            'image/png' => 'png',
                            'image/gif' => 'gif',
                            'video/mp4' => 'mp4',
                            'video/quicktime' => 'mov',
                            'audio/mpeg' => 'mp3',
                            'audio/ogg' => 'ogg',
                            'audio/opus' => 'opus',
                            'application/pdf' => 'pdf',
                            'application/zip' => 'zip',
                            'application/octet-stream' => 'bin',
                            'image/webp' => 'webp',
                            'video/webm' => 'webm',
                        ];
                        return $map[$mime] ?? null;
                    };

                    $getExtension = function($fileName, $mime, $default) use ($mimeMap) {
                        if ($fileName) {
                            $ext = pathinfo($fileName, PATHINFO_EXTENSION);
                            if ($ext) return '.' . $ext;
                        }
                        if ($mime) {
                            $mext = $mimeMap($mime);
                            if ($mext) return '.' . $mext;
                            $parts = explode('/', $mime);
                            if (count($parts) === 2) return '.' . $parts[1];
                        }
                        return '.' . $default;
                    };

                    if (!empty($msg['media']) && is_array($msg['media'])) {
                        $media = $msg['media'];
                        if (isset($media['photo'])) {
                            $mediaType = 'photo';
                            $providerFileId = json_encode($media['photo']);
                        } elseif (isset($media['document'])) {
                            $mediaType = 'document';
                            $providerFileId = json_encode($media['document']);
                            $mediaFileName = $media['document']['file_name'] ?? null;
                            $mediaMime = $media['document']['mime_type'] ?? null;
                            $mediaSize = $media['document']['size'] ?? null;
                        } elseif (isset($media['video'])) {
                            $mediaType = 'video';
                            $providerFileId = json_encode($media['video']);
                            $mediaSize = $media['video']['size'] ?? null;
                            $mediaMime = $media['video']['mime_type'] ?? null;
                        } elseif (isset($media['voice'])) {
                            $mediaType = 'voice';
                            $providerFileId = json_encode($media['voice']);
                            $mediaSize = $media['voice']['size'] ?? null;
                            $mediaMime = $media['voice']['mime_type'] ?? null;
                        } elseif (isset($media['audio'])) {
                            $mediaType = 'audio';
                            $providerFileId = json_encode($media['audio']);
                            $mediaSize = $media['audio']['size'] ?? null;
                            $mediaMime = $media['audio']['mime_type'] ?? null;
                        } elseif (isset($media['contact'])) {
                            $mediaType = 'contact';
                            $providerFileId = json_encode($media['contact']);
                        }
                    }

                    // ---------- MEDIA: decide to skip download if already present ----------
                    if ($existingLatest && $existingLatest->media_local_path) {
                        // compute disk path from stored 'storage/telegram_media/...' path
                        $stored = $existingLatest->media_local_path;
                        $diskPath = null;
                        if (is_string($stored) && str_starts_with($stored, 'storage/')) {
                            $relative = substr($stored, strlen('storage/')); // 'telegram_media/...'
                            $diskPath = storage_path('app/public/' . $relative);
                        } else {
                            // fallback: assume stored relative to app/public
                            $diskPath = storage_path('app/public/' . ltrim($stored, '/'));
                        }
                        if ($diskPath && File::exists($diskPath)) {
                            // reuse existing file path, skip download
                            $mediaLocalPath = $existingLatest->media_local_path;
                            $thumbLocalPath = $existingLatest->thumb_local_path;
                            $mediaMime = $existingLatest->media_mime ?? $mediaMime;
                            $mediaFileName = $existingLatest->media_file_name ?? $mediaFileName;
                            $mediaSize = $existingLatest->media_size ?? $mediaSize;
                        }
                        // if file missing on disk, we'll fallthrough to download below
                    }

                    // ---------- DOWNLOAD media only if needed and mediaType present and not already reused ----------
                    if ($mediaType !== null && $mediaLocalPath === null) {
                        try {
                            $phoneSafe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $phone->phone ?? (string)$phone->id);
                            $peerSafe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $this->peer);
                            $peerSafe = Str::limit($peerSafe, 120, '');
                            $dir = storage_path("app/public/telegram_media/{$phoneSafe}/{$peerSafe}/");
                            if (!File::exists($dir)) File::makeDirectory($dir, 0755, true);

                            $safeNamePart = $mediaFileName ? preg_replace('/[^A-Za-z0-9_\-\.]/', '_', pathinfo($mediaFileName, PATHINFO_FILENAME)) : $mediaType;
                            $ext = $getExtension($mediaFileName, $mediaMime, $mediaType);
                            $basename = $messageId . '_' . $safeNamePart;
                            $target = $dir . $basename . $ext;
                            if (File::exists($target)) $target = $dir . $basename . '_' . uniqid() . $ext;

                            if (method_exists($Madeline, 'downloadToFile')) {
                                $Madeline->downloadToFile($msg['media'], $target);
                            } elseif (method_exists($Madeline, 'downloadToDir')) {
                                $Madeline->downloadToDir($msg['media'], $dir);
                                $files = glob($dir . '*');
                                $target = end($files) ?: $target;
                            } else {
                                $Madeline->downloadToFile($msg['media'], $target);
                            }

                            if (File::exists($target)) {
                                $realExt = pathinfo($target, PATHINFO_EXTENSION);
                                $badExts = ['', 'photo', 'dat', 'bin'];
                                if (in_array(strtolower($realExt), $badExts, true)) {
                                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                                    $detectedMime = finfo_file($finfo, $target) ?: null;
                                    finfo_close($finfo);
                                    $detectedExt = null;
                                    if ($detectedMime) {
                                        $detectedExt = ($mimeMap($detectedMime)) ? $mimeMap($detectedMime) : explode('/', $detectedMime)[1] ?? null;
                                    }
                                    if ($detectedExt) {
                                        $newTarget = $dir . $basename . '.' . $detectedExt;
                                        if (File::exists($newTarget)) $newTarget = $dir . $basename . '_' . uniqid() . '.' . $detectedExt;
                                        File::move($target, $newTarget);
                                        $target = $newTarget;
                                    }
                                }
                            } else {
                                $files = glob($dir . '*');
                                if (!empty($files)) $target = end($files);
                            }

                            if (File::exists($target)) {
                                $mediaLocalPath = 'storage/telegram_media/' . $phoneSafe . '/' . $peerSafe . '/' . basename($target);
                            } else {
                                $mediaLocalPath = null;
                            }

                            if ($mediaType === 'photo' || $mediaType === 'video') $thumbLocalPath = $mediaLocalPath;
                        } catch (\Throwable $e) {
                            Log::error("Media download failed for msg {$messageId}: " . $e->getMessage());
                        }
                    }
                    
                    // ---------- DB payload ----------
                    $payload = [
                        'chat_log_group_id' => $group->id,
                        'message_id' => $messageId,
                        // caption stores the raw/original incoming text (no edited suffix)
                        'caption' => $incomingText ?? null,
                        // message_text will be either same as caption (new) or caption + edited tag (if versioned)
                        'message_text' => $incomingText ?? null,
                        'message_date' => isset($msg['date']) ? date('Y-m-d H:i:s', $msg['date']) : null,
                        'from_user_id' => is_int($fromUserId) ? $fromUserId : null,
                        'from_username' => $fromUsername,
                        'media_type' => $mediaType,
                        'media_mime' => $mediaMime,
                        'media_file_name' => $mediaFileName,
                        'media_size' => $mediaSize,
                        'media_local_path' => $mediaLocalPath,
                        'thumb_local_path' => $thumbLocalPath,
                        'provider_file_id' => $providerFileId,
                    ];
                    $group->update([
                        'last_fetch_at' => Carbon::now(),
                    ]);

                    // ---------- VERSIONING (text only) but compare against caption (original) ----------
                    $existingCaptionNormalized = $existingLatest ? trim((string)($existingLatest->caption ?? '')) : '';

                    if (!$existingLatest) {
                        $created = ChatLog::create($payload);
                    } else {
                        // If incoming caption (original) differs from stored caption -> create new version
                        if ($incomingNormalized !== $existingCaptionNormalized) {
                            $version = ChatLog::where('chat_log_group_id', $group->id)
                                ->where('message_id', $messageId)
                                ->count() + 1;
                            // annotate message_text with edited counter, but keep caption as raw incoming
                            $payload['message_text'] = ($payload['message_text'] ?? '') . " (edited #{$version})";
                            $created = ChatLog::create($payload);
                        } else {
                            // No text change -> ignore (also don't redownload media because we already skipped)
                        }
                    }
                }

                Log::info("Messages fetched done for phone {$phone->phone} and chat {$this->peer}");
            } catch (\Throwable $e) {
                Log::error("Error fetching messages for chat {$this->peer}: {$e->getMessage()}", ['trace' => $e->getTraceAsString()]);
            }
        } catch (\Throwable $e) {
            Log::error("FetchChatMessagesJob error: {$e->getMessage()}", ['trace' => $e->getTraceAsString()]);
        }
    }
}
