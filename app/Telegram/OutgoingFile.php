<?php

declare(strict_types=1);

namespace App\Telegram;

/** A local file to send: as a photo (compressed, shown inline) or as a document. */
final readonly class OutgoingFile
{
    public function __construct(
        public string $path,
        public string $name,
        public ?string $mimeType,
        public bool $asPhoto,
    ) {}
}
