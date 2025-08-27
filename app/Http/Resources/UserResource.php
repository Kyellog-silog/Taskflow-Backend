<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $isOwnProfile = $request->user() && $request->user()->id === $this->id;
        
        return [
            'id' => $this->id,
            // Show full name to own profile, masked to others
            'name' => $isOwnProfile ? $this->name : $this->getDisplayName(),
            // Show full email to own profile, masked to others  
            'email' => $isOwnProfile ? $this->email : $this->getMaskedEmail(),
            'avatar' => $this->avatar,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
