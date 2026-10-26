<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Catalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Catalog */
final class CatalogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'peers_count' => count($this->peerList()),
            'peers' => $this->peerList(),
            'is_global' => $this->is_global,
            'timer_minutes' => $this->timer_minutes,
            'owner' => $this->whenLoaded('user', fn () => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name] : null),
            'is_mine' => $this->user_id === $user?->id,
            'updated_at' => $this->updated_at?->toIso8601String(),
            'can' => [
                'update' => $user?->can('update', $this->resource) ?? false,
                'delete' => $user?->can('delete', $this->resource) ?? false,
            ],
        ];
    }
}
