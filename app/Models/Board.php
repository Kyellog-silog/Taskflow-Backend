<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Board extends Model
{
    use HasFactory, SoftDeletes;
    
    protected $fillable = [
        'name',
        'description',
        'team_id',
        'created_by',
        'archived_at',
        'last_visited_at',
    ];

    // Eager loading disabled for performance optimization - load explicitly when needed

    protected $casts = [
        'archived_at' => 'datetime',
        'last_visited_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the team that owns the board, with safe null handling.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class)->withDefault();
    }

    /**
     * Get the user who created the board.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
    /**
     * Get the completion percentage
     */
    public function getCompletionPercentageAttribute(): int
{
    $totalTasks = $this->tasks()->count();
    if ($totalTasks === 0) {
        return 0;
    }
    
    // Get tasks in the "Done" column
    $completedTasks = $this->tasks()
        ->whereHas('column', function ($query) {
            $query->where('name', 'Done');
        })
        ->count();
    
    return (int) round(($completedTasks / $totalTasks) * 100);
}

    /**
     * Get the columns of the board, ordered by position.
     */
    public function columns(): HasMany
    {
        return $this->hasMany(BoardColumn::class)->orderBy('position');
    }

    /**
     * Get all tasks of the board.
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Get only non-deleted tasks of the board.
     */
    public function activeTasks(): HasMany
    {
        return $this->hasMany(Task::class)->whereNull('deleted_at');
    }

    /**
     * Scope to get boards accessible by a specific user.
     * This includes:
     * - Personal boards created by the user
     * - Boards from teams where the user is a member
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where(function($q) use ($userId) {
            $q->where('created_by', $userId)
              ->whereNull('team_id')
              ->orWhereHas('team', function ($q) use ($userId) {
                  $q->forUser($userId);
              });
        });
    }

    /**
     * Scope to get only active (non-archived, non-deleted) boards
     */
    public function scopeActive($query)
    {
        return $query->whereNull('archived_at');
    }

    /**
     * Scope to get only archived boards
     */
    public function scopeArchived($query)
    {
        return $query->whereNotNull('archived_at');
    }

    /**
     * Scope to get recently visited boards
     */
    public function scopeRecentlyVisited($query, $limit = 5)
    {
        return $query->whereNotNull('last_visited_at')
                    ->orderBy('last_visited_at', 'desc')
                    ->limit($limit);
    }

    /**
     * Check if board is archived
     */
    public function isArchived(): bool
    {
        return !is_null($this->archived_at);
    }

    /**
     * Archive the board
     */
    public function archive(): bool
    {
        return $this->update(['archived_at' => now()]);
    }

    /**
     * Unarchive the board
     */
    public function unarchive(): bool
    {
        return $this->update(['archived_at' => null]);
    }

    /**
     * Update last visited timestamp
     */
    public function updateLastVisited(): bool
    {
        return $this->update(['last_visited_at' => now()]);
    }

    /**
     * Check if a user can access this board.
     */
    public function canUserAccess(User $user): bool
    {
        // Personal boards are only accessible by their creator
        if (!$this->team_id) {
            return $this->created_by == $user->id;
        }
        
        // Team boards are accessible by all team members (including viewers)
        return $this->team->isMember($user);
    }

    /**
     * Check if a user can manage this board.
     */
    public function canUserManage(User $user): bool
    {
        // Personal boards are only manageable by their creator
        if (!$this->team_id) {
            return $this->created_by == $user->id;
        }
        
        // Team boards are manageable by team admins and the board creator (but not viewers)
        return $this->team->isAdmin($user) || $this->createdBy?->id === $user->id;
    }

    /**
     * Check if a user can edit tasks on this board.
     */
    public function canUserEditTasks(User $user): bool
    {
        // Personal boards can be edited by their creator
        if (!$this->team_id) {
            return $this->created_by == $user->id;
        }
        
        // Team boards: viewers cannot edit tasks
        return $this->team->canEditTasks($user);
    }

    /**
     * Check if a user can create tasks on this board.
     */
    public function canUserCreateTasks(User $user): bool
    {
        return $this->canUserEditTasks($user);
    }

    /**
     * Get the user's role for this board.
     */
    public function getUserRole(User $user): ?string
    {
        // Personal boards - creator is owner
        if (!$this->team_id) {
            return $this->created_by == $user->id ? 'owner' : null;
        }
        
        // Team boards - get role from team
        return $this->team->getUserRole($user);
    }

    /**
     * Check if user is a viewer (read-only access).
     */
    public function isUserViewer(User $user): bool
    {
        // Personal boards don't have viewers
        if (!$this->team_id) {
            return false;
        }
        
        return $this->team->isViewer($user);
    }
}
