<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class BoardColumnResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'board_id' => $this->board_id,
            'name' => $this->name,
            'position' => $this->position,
            'color' => $this->color,
        ];
    }
}
