<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class LabelResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'name' => $this->name,
            'color' => $this->color,
        ];
    }
}
