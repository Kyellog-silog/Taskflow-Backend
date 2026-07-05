<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TransitionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'from_status_id' => $this->from_status_id,
            'to_status_id' => $this->to_status_id,
            'name' => $this->name,
            'allowed_roles' => $this->allowed_roles,
            'from_status' => $this->whenLoaded('fromStatus', fn () => new StatusResource($this->fromStatus)),
            'to_status' => $this->whenLoaded('toStatus', fn () => new StatusResource($this->toStatus)),
        ];
    }
}
