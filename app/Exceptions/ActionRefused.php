<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * An action that can't run in the current state (a login already in progress,
 * a catalog on cooldown ...). The reason is a translation key under
 * lang/{locale}/errors.php "refused"; web shows it as a flash, the API as 409.
 */
final class ActionRefused extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly string $reason,
        public readonly array $details = [],
        public readonly int $status = 409,
    ) {
        parent::__construct($reason);
    }

    public function translated(): string
    {
        return __("errors.refused.{$this->reason}", $this->details);
    }

    public function render(): mixed
    {
        if (request()->expectsJson() || request()->is('api/*')) {
            return response()->json([
                'status' => 'error',
                'success' => false,
                'reason' => $this->reason,
                'message' => $this->translated(),
                'details' => $this->details ?: null,
            ], $this->status);
        }

        return back()->withInput()->with('error', $this->translated());
    }
}
