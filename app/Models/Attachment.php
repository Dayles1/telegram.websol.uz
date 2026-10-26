<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * A file sent with a message. Lives on the local disk until nothing can send it
 * any more (App\Sending\Attachments::cleanup), then only the row is kept.
 */
class Attachment extends Model
{
    public const KIND_PHOTO = 'photo';

    public const KIND_DOCUMENT = 'document';

    protected $fillable = ['user_id', 'path', 'original_name', 'mime_type', 'size', 'kind', 'removed_at'];

    protected $hidden = ['path', 'user_id'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'removed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(MessageGroup::class);
    }

    public function loops(): HasMany
    {
        return $this->hasMany(SendLoop::class);
    }

    public function isRemoved(): bool
    {
        return $this->removed_at !== null;
    }

    public function absolutePath(): string
    {
        return Storage::disk('local')->path($this->path);
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->original_name,
            'size' => $this->size,
            'mime_type' => $this->mime_type,
            'kind' => $this->kind,
            'removed' => $this->isRemoved(),
        ];
    }
}
