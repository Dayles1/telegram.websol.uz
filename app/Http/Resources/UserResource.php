<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'telegram_id' => $this->telegram_id,
            'oferta_read' => $this->oferta_read,

            'role' => [
                'id' => $this->role_id,
                'name' => optional($this->role)->name, // relation load qilinmasa null bo‘ladi
            ],

            'department' => $this->whenLoaded('department', function () {
                return [
                    'id' => $this->department->id,
                    'name' => $this->department->name,
                ];
            }, $this->department_id),

            'state' => $this->state,
            'value' => $this->value,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
