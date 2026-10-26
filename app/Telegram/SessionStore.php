<?php

declare(strict_types=1);

namespace App\Telegram;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Where session files live, and the only code allowed to delete them.
 *
 * Old sessions keep the absolute path stored on their user_phones row. New ones
 * follow the same naming the old code used ("{digits}_user_{id}.madeline").
 * Deleting is refused for any path outside the sessions directory, so a bad
 * value in the database can never remove something else.
 */
final class SessionStore
{
    public function pathFor(string $phone, int $userId): string
    {
        return $this->root().DIRECTORY_SEPARATOR.Phone::digits($phone)."_user_{$userId}.madeline";
    }

    public function exists(?string $path): bool
    {
        return $path !== null && $path !== '' && file_exists($path);
    }

    public function delete(?string $path): void
    {
        if (! $this->exists($path) || ! $this->isInside($path)) {
            return;
        }

        File::isDirectory($path) ? File::deleteDirectory($path) : File::delete($path);

        // MadelineProto keeps sidecar files next to the session (.lock, .ipc, .safe.php ...).
        foreach (File::glob($path.'.*') as $sidecar) {
            File::isDirectory($sidecar) ? File::deleteDirectory($sidecar) : File::delete($sidecar);
        }
    }

    public function root(): string
    {
        $root = (string) config('telegram.sessions_path');
        File::ensureDirectoryExists($root, 0750);

        return $root;
    }

    private function isInside(string $path): bool
    {
        $root = realpath($this->root());
        $real = realpath($path);

        return $root !== false && $real !== false && Str::startsWith($real, $root.DIRECTORY_SEPARATOR);
    }
}
