<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PeerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'peer' => $this->peer,
            'peer_id' => $this->peer_id,
            'peer_type' => $this->peer_type,
            'title' => $this->title,
            'username' => $this->username,
            'usage_count' => $this->usage_count,
            'last_resolved_at' => $this->last_resolved_at->toDateTimeString(),
            'created_at' => $this->created_at->toDateTimeString(),
            'updated_at' => $this->updated_at->toDateTimeString(),
        ];
    }
}
