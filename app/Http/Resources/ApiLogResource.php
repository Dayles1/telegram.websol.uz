<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ApiLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One API log row. The list gets a short preview of the request; the bodies
 * themselves only come with `withBodies()` (the detail view).
 *
 * @mixin ApiLog
 */
final class ApiLogResource extends JsonResource
{
    private bool $bodies = false;

    public function withBodies(): self
    {
        $this->bodies = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'method' => $this->method,
            'endpoint' => $this->endpoint,
            'path' => $this->path,
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', fn () => $this->user?->name),
            'status' => $this->status,
            'tone' => match (true) {
                $this->status >= 500 => 'error',
                $this->status >= 400 => 'warning',
                default => 'success',
            },
            'duration_ms' => $this->duration_ms,
            'ip' => $this->ip,
            'user_agent' => $this->user_agent,
            'created_at' => $this->created_at->toIso8601String(),
            'preview' => $this->when(! $this->bodies, fn () => $this->request_body === null ? null : mb_substr($this->request_body, 0, 160)),
            'request_body' => $this->when($this->bodies, fn () => $this->request_body),
            'response_body' => $this->when($this->bodies, fn () => $this->response_body),
        ];
    }
}
