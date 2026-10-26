<?php

declare(strict_types=1);

namespace App\ChatLogs;

use App\Models\ChatLog;
use App\Models\ChatLogGroup;
use App\Telegram\Contracts\TelegramSession;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * Copies the last messages of one chat into chat_logs. A message whose text
 * changed since the last fetch gets a new row marked "(edited #n)"; media is
 * downloaded once into storage/app/public/telegram_media.
 */
final class ChatHistoryFetcher
{
    private const SAFE_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'mp4', 'mov', 'webm', 'mkv', 'avi', 'mp3', 'ogg', 'oga', 'opus',
        'm4a', 'wav', 'flac', 'pdf', 'txt', 'csv', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'rar', '7z', 'tgs',
    ];

    private const MEDIA_KINDS = ['photo', 'document', 'video', 'voice', 'audio', 'contact'];

    public function fetch(TelegramSession $session, ChatLogGroup $group, ?string $ownTelegramId, int $limit = 100): int
    {
        $history = $session->history($group->peer, $limit);
        $users = collect($history['users'])->mapWithKeys(fn (array $u) => [$u['id'] => $u['username'] ?? $u['first_name'] ?? null]);
        $chats = collect($history['chats'])->mapWithKeys(fn (array $c) => [$c['id'] => $c['title'] ?? null]);

        $stored = 0;
        foreach ($history['messages'] as $message) {
            if (! isset($message['id'])) {
                continue;
            }
            $group->chat_type ??= $message['_'] ?? null;
            $stored += $this->store($session, $group, $message, $users->all(), $chats->all(), $ownTelegramId) ? 1 : 0;
        }

        // status is the caller's choice ("fetching" = follow daily), so it is left alone.
        $group->forceFill(['last_fetch_at' => now()])->save();

        return $stored;
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<int|string, ?string>  $users
     * @param  array<int|string, ?string>  $chats
     */
    private function store(TelegramSession $session, ChatLogGroup $group, array $message, array $users, array $chats, ?string $ownId): bool
    {
        $text = $message['media']['caption'] ?? $message['message'] ?? null;
        $latest = ChatLog::query()->where('chat_log_group_id', $group->id)->where('message_id', $message['id'])->latest('id')->first();

        if ($latest !== null && trim((string) $latest->caption) === trim((string) $text)) {
            return false;
        }

        [$fromId, $fromName] = $this->sender($message, $users, $chats, $ownId);
        $media = $this->media($message);

        if ($media !== null) {
            $reusable = $latest?->media_local_path && File::exists(storage_path('app/public/'.Str::after($latest->media_local_path, 'storage/')));
            $media['media_local_path'] = $reusable ? $latest->media_local_path : $this->download($session, $group, $message, $media);
            $media['thumb_local_path'] = in_array($media['media_type'], ['photo', 'video'], true) ? $media['media_local_path'] : null;
        }

        $version = $latest === null ? 1 : ChatLog::query()->where('chat_log_group_id', $group->id)->where('message_id', $message['id'])->count() + 1;

        ChatLog::query()->create(($media ?? []) + [
            'chat_log_group_id' => $group->id,
            'message_id' => $message['id'],
            'caption' => $text,
            'message_text' => $version > 1 ? trim(($text ?? '')." (edited #{$version})") : $text,
            'message_date' => isset($message['date']) ? date('Y-m-d H:i:s', (int) $message['date']) : null,
            'from_user_id' => $fromId,
            'from_username' => $fromName,
        ]);

        return true;
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<int|string, ?string>  $users
     * @param  array<int|string, ?string>  $chats
     * @return array{0: ?int, 1: string}
     */
    private function sender(array $message, array $users, array $chats, ?string $ownId): array
    {
        $from = $message['from_id'] ?? $message['peer_id'] ?? null;

        $userId = match (true) {
            is_int($from) && $from > 0 => $from,
            is_array($from) && isset($from['user_id']) => (int) $from['user_id'],
            default => null,
        };
        $chatId = match (true) {
            is_int($from) && $from < 0 => abs($from),
            is_array($from) => $from['channel_id'] ?? $from['chat_id'] ?? null,
            default => null,
        };

        $name = match (true) {
            $userId !== null && isset($users[$userId]) => $users[$userId],
            $userId !== null && $ownId !== null && (string) $userId === $ownId => 'Me',
            $chatId !== null => $chats[$chatId] ?? null,
            default => null,
        };

        return [$userId, $name ?? 'Unknown'];
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>|null
     */
    private function media(array $message): ?array
    {
        $media = $message['media'] ?? null;
        if (! is_array($media)) {
            return null;
        }

        foreach (self::MEDIA_KINDS as $kind) {
            if (! isset($media[$kind])) {
                continue;
            }
            $payload = $media[$kind];

            return [
                'media_type' => $kind,
                'media_file_name' => $payload['file_name'] ?? null,
                'media_mime' => $payload['mime_type'] ?? null,
                'media_size' => $payload['size'] ?? null,
                'provider_file_id' => json_encode($payload),
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>  $media
     */
    private function download(TelegramSession $session, ChatLogGroup $group, array $message, array $media): ?string
    {
        if ($media['media_type'] === 'contact') {
            return null;
        }

        $safe = fn (string $value) => Str::limit(preg_replace('/[^A-Za-z0-9_\-]/', '_', $value) ?? '', 120, '');
        $relative = 'telegram_media/'.$safe((string) $group->user_phone_id).'/'.$safe($group->peer);
        $dir = storage_path('app/public/'.$relative);
        File::ensureDirectoryExists($dir);

        $target = $dir.'/'.$message['id'].'_'.$media['media_type'].'.'.$this->extension($media);

        try {
            $path = $session->download($message['media'], $target);
        } catch (Throwable) {
            return null;
        }

        return 'storage/'.$relative.'/'.basename($path);
    }

    /**
     * The files are served from public/storage, and their names and types come from
     * whoever posted them: only plain media extensions, so an uploaded .html, .svg
     * or .php can never run on the panel's domain.
     *
     * @param  array<string, mixed>  $media
     */
    private function extension(array $media): string
    {
        $extension = strtolower(pathinfo((string) $media['media_file_name'], PATHINFO_EXTENSION)
            ?: (explode('/', (string) $media['media_mime'])[1] ?? ''));

        return in_array($extension, self::SAFE_EXTENSIONS, true) ? $extension : 'bin';
    }
}
