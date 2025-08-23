<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class BoardResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'team_id' => $this->team_id,
            'created_by' => $this->created_by,
            'archived_at' => $this->archived_at,
            'last_visited_at' => $this->last_visited_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'tasks_count' => $this->when(isset($this->tasks_count), $this->tasks_count),
            'createdBy' => $this->whenLoaded('createdBy', fn() => new UserSummaryResource($this->createdBy)),
            'team' => $this->whenLoaded('team', function () {
                return [
                    'id' => $this->team?->id,
                    'name' => $this->team?->name,
                    'owner_id' => $this->team?->owner_id,
                ];
            }),
            'columns' => BoardColumnResource::collection($this->whenLoaded('columns')),
        ];
    }
}
