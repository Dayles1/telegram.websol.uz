<?php

declare(strict_types=1);

namespace App\Sending;

use Illuminate\Http\UploadedFile;

/**
 * What a person asked to send: to the peers of some catalogs and/or to peers
 * given directly, a text and/or a file, once or again and again.
 */
final readonly class SendOrder
{
    /**
     * @param  list<int>  $catalogIds
     * @param  list<string>  $peers  as typed: "@name", "t.me/name", "+998...", an id
     * @param  int|null  $repeatEveryMinutes  null = send once
     * @param  int|null  $maxRounds  with repeat: stop after this many rounds (null = until stopped)
     */
    public function __construct(
        public array $catalogIds = [],
        public array $peers = [],
        public string $text = '',
        public ?UploadedFile $file = null,
        public ?int $repeatEveryMinutes = null,
        public ?int $maxRounds = null,
    ) {}

    public function repeats(): bool
    {
        return $this->repeatEveryMinutes !== null && $this->repeatEveryMinutes > 0;
    }
}
