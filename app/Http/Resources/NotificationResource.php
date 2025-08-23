<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'data' => [
                'task_id' => $this->data['task_id'] ?? null,
                'board_id' => $this->data['board_id'] ?? null,
                'comment_id' => $this->data['comment_id'] ?? null,
                'actor_id' => $this->data['actor_id'] ?? null,
            ],
            'read_at' => $this->read_at,
            'created_at' => $this->created_at,
        ];
    }
}
