<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'team_id' => $this->team_id,
            'name' => $this->name,
            'key' => $this->key,
            'description' => $this->description,
            'lead_user_id' => $this->lead_user_id,
            'issue_counter' => $this->issue_counter,
            'boards_count' => $this->whenCounted('boards'),
            'tasks_count' => $this->whenCounted('tasks'),
            'lead' => $this->whenLoaded('lead', fn () => new UserSummaryResource($this->lead)),
            'team' => $this->whenLoaded('team', fn () => $this->team_id ? [
                'id' => $this->team->id,
                'name' => $this->team->name,
            ] : null),
            'labels' => LabelResource::collection($this->whenLoaded('labels')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
