<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'board_id' => $this->board_id,
            'column_id' => $this->column_id,
            'assignee_id' => $this->assignee_id,
            'priority' => $this->priority,
            'due_date' => $this->due_date,
            'position' => $this->position,
            'completed_at' => $this->completed_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'assignee' => $this->whenLoaded('assignee', fn() => new UserSummaryResource($this->assignee)),
            'createdBy' => $this->whenLoaded('createdBy', fn() => new UserSummaryResource($this->createdBy)),
            'board' => $this->whenLoaded('board', function () {
                return [
                    'id' => $this->board?->id,
                    'name' => $this->board?->name,
                ];
            }),
            'column' => $this->whenLoaded('column', function () {
                return [
                    'id' => $this->column?->id,
                    'name' => $this->column?->name,
                ];
            }),
            'comments' => TaskCommentResource::collection($this->whenLoaded('comments')),
        ];
    }
}
