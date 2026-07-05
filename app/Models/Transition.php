<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transition extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'from_status_id',
        'to_status_id',
        'name',
        'allowed_roles',
    ];

    protected $casts = [
        'allowed_roles' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function fromStatus(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'from_status_id');
    }

    public function toStatus(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'to_status_id');
    }

    /**
     * Whether a team role may use this transition. Empty allowed_roles means
     * any editing member; viewers can never transition (enforced upstream by
     * the task move gate as well).
     */
    public function roleAllowed(?string $role): bool
    {
        if ($role === null || $role === 'viewer') {
            return false;
        }
        if (empty($this->allowed_roles)) {
            return true;
        }

        return in_array($role, $this->allowed_roles, true);
    }
}
