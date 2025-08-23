<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $team_id
 * @property int $invited_by
 * @property string $email
 * @property string $token
 * @property string $role
 * @property string $status
 * @property \Carbon\Carbon $expires_at
 * @property \Carbon\Carbon|null $accepted_at
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
class TeamInvitation extends Model
{
    use HasFactory;

    protected $fillable = [
        'team_id',
        'invited_by',
        'email',
        'token',
        'role',
        'status',
        'expires_at',
        'accepted_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function invitedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'email', 'email');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($invitation) {
            if (empty($invitation->token)) {
                $invitation->token = (string) Str::uuid();
            }
            
            if (empty($invitation->expires_at)) {
                $invitation->expires_at = now()->addDays(7); // Expire after 7 days
            }
        });
    }

    public function isExpired(): bool
    {
        return now()->isAfter($this->expires_at);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending' && !$this->isExpired();
    }

    public function accept(): bool
    {
        if (!$this->isPending()) {
            return false;
        }

        $this->update([
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);

        return true;
    }

    public function reject(): bool
    {
        if (!$this->isPending()) {
            return false;
        }

        $this->update(['status' => 'rejected']);
        return true;
    }

    public function markAsExpired(): bool
    {
        if ($this->status !== 'pending') {
            return false;
        }

        $this->update(['status' => 'expired']);
        return true;
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending')
                    ->where('expires_at', '>', now());
    }

    public function scopeForEmail($query, string $email)
    {
        return $query->where('email', $email);
    }

    public function scopeForTeam($query, int $teamId)
    {
        return $query->where('team_id', $teamId);
    }
}
