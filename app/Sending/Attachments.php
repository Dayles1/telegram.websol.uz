<?php

declare(strict_types=1);

namespace App\Sending;

use App\Enums\EventKind;
use App\Enums\GroupStatus;
use App\Enums\LoopStatus;
use App\Models\Attachment;
use App\Models\User;
use App\Monitoring\EventRecorder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Files sent with a message: stored on the local disk (never public), deleted
 * once nothing can send them any more.
 */
final class Attachments
{
    /** Telegram compresses images up to this size into a photo; bigger ones go as a file. */
    public const PHOTO_MAX_BYTES = 10 * 1024 * 1024;

    public const PHOTO_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private readonly EventRecorder $events) {}

    /** Validation rules for an uploaded file, shared by the web form and the API. */
    public static function rules(): array
    {
        return ['nullable', 'file', 'max:'.(int) config('telegram.attachments.max_kb')];
    }

    public function store(UploadedFile $file, User $owner): Attachment
    {
        $path = $file->store('attachments/'.now()->format('Y/m'), 'local');
        if ($path === false) {
            throw new RuntimeException('Could not store the uploaded file');
        }

        $mime = $file->getMimeType();
        $size = (int) $file->getSize();

        return Attachment::query()->create([
            'user_id' => $owner->id,
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName() ?: basename($path), 0, 255),
            'mime_type' => $mime,
            'size' => $size,
            'kind' => in_array($mime, self::PHOTO_MIME_TYPES, true) && $size <= self::PHOTO_MAX_BYTES
                ? Attachment::KIND_PHOTO
                : Attachment::KIND_DOCUMENT,
        ]);
    }

    /** Removes the file at once (the send that was to carry it was not created). */
    public function discard(Attachment $attachment): void
    {
        Storage::disk('local')->delete($attachment->path);
        $attachment->delete();
    }

    /**
     * Deletes files nothing can send any more: no unfinished send, no active
     * repeating send, and the last send finished more than keep_minutes ago.
     * Returns how many were deleted.
     */
    public function cleanup(): int
    {
        $keepSince = now()->subMinutes(max(0, (int) config('telegram.attachments.keep_minutes')));

        $unused = Attachment::query()
            ->whereNull('removed_at')
            ->where('created_at', '<', now()->subMinutes(10)) // an upload whose send is being created right now
            ->whereDoesntHave('groups', fn ($q) => $q->where(fn ($w) => $w
                ->whereIn('status', GroupStatus::activeValues())
                ->orWhere('finished_at', '>', $keepSince)
                ->orWhere(fn ($r) => $r->whereNull('finished_at')->where('updated_at', '>', $keepSince))))
            ->whereDoesntHave('loops', fn ($q) => $q->where('status', LoopStatus::Active))
            ->limit(500)
            ->get();

        foreach ($unused as $attachment) {
            Storage::disk('local')->delete($attachment->path);
            $attachment->update(['removed_at' => now()]);
        }

        if ($unused->isNotEmpty()) {
            $this->events->info(EventKind::Sending, 'Files nobody will send any more were deleted', [
                'files' => $unused->count(),
                'bytes' => $unused->sum('size'),
            ]);
        }

        return $unused->count();
    }
}
