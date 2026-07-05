<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class Task extends Model
{
    use HasFactory, SoftDeletes;

    public const ISSUE_TYPES = ['epic', 'story', 'task', 'bug', 'subtask'];

    public const PRIORITIES = ['highest', 'high', 'medium', 'low', 'lowest'];

    /**
     * The attributes that are mass assignable.
     * project_id and issue_key are intentionally NOT fillable — they are
     * server-assigned at creation and immutable afterwards.
     *
     * @var array
     */
    protected $fillable = [
        'title',
        'description',
        'board_id',
        'column_id',
        'assignee_id',
        'created_by',
        'priority',
        'due_date',
        'position',
        'completed_at',
        'issue_type',
        'story_points',
        'parent_id',
        'epic_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'due_date' => 'date',
        'completed_at' => 'datetime',
        'position' => 'integer',
        'story_points' => 'integer',
        // Encrypt sensitive task data
        'description' => 'encrypted',
    ];

    /**
     * The relationships that should be eager loaded.
     *
     * @var array
     */
    // Eager loading disabled for performance optimization - load explicitly when needed

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = ['is_overdue', 'is_completed', 'status'];

    /**
     * Get the board that owns the task.
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class, 'board_id', 'id');
    }

    /**
     * Get the column that owns the task.
     */
    public function column(): BelongsTo
    {
        return $this->belongsTo(BoardColumn::class, 'column_id', 'id');
    }

    /**
     * Get the project the task belongs to.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the parent task (for subtasks).
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_id');
    }

    /**
     * Get the subtasks of this task.
     */
    public function subtasks(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_id');
    }

    /**
     * Get the epic this task belongs to.
     */
    public function epic(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'epic_id');
    }

    /**
     * Get the tasks under this epic.
     */
    public function epicChildren(): HasMany
    {
        return $this->hasMany(Task::class, 'epic_id');
    }

    /**
     * Get the labels attached to this task.
     */
    public function labels()
    {
        return $this->belongsToMany(Label::class);
    }

    /**
     * Get the user assigned to the task.
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id', 'id');
    }

    /**
     * Get the user who created the task.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }

    /**
     * Get the comments for the task.
     */
    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class, 'task_id', 'id')->latest();
    }

    /**
     * Get the attachments for the task.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(TaskAttachment::class, 'task_id', 'id');
    }

    /**
     * Get the activities for the task.
     */
    public function activities(): HasMany
    {
        return $this->hasMany(TaskActivity::class, 'task_id', 'id')->latest();
    }

    /**
     * Scope a query to only include tasks for a specific board.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int|string  $boardId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByBoard($query, $boardId)
    {
        return $query->select('id', 'title', 'description', 'board_id', 'column_id', 'assignee_id', 'created_by', 'priority', 'due_date', 'position', 'completed_at', 'created_at', 'updated_at')
            ->where('board_id', $boardId);
    }

    /**
     * Scope a query to only include tasks for a specific column.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int|string  $columnId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByColumn($query, $columnId)
    {
        return $query->where('column_id', $columnId);
    }

    /**
     * Scope a query to only include tasks assigned to a specific user.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int|string  $assigneeId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByAssignee($query, $assigneeId)
    {
        return $query->where('assignee_id', $assigneeId);
    }

    /**
     * Scope a query to only include tasks with a specific priority.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $priority
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByPriority($query, $priority)
    {
        return $query->where('priority', $priority);
    }

    /**
     * Scope a query to order tasks by position.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOrderedByPosition($query)
    {
        return $query->orderBy('position');
    }

    /**
     * Get if the task is overdue.
     */
    public function getIsOverdueAttribute(): bool
    {
        return $this->due_date &&
               $this->due_date < now() &&
               ! $this->completed_at;
    }

    /**
     * Get if the task is completed.
     */
    public function getIsCompletedAttribute(): bool
    {
        return ! is_null($this->completed_at);
    }

    /**
     * Get the status of the task based on its column.
     */
    public function getStatusAttribute(): string
    {
        return $this->column ? strtolower(str_replace(' ', '-', $this->column->name)) : 'unknown';
    }

    /**
     * Mark a task as completed.
     *
     * @param  User|null  $user  The user completing the task
     */
    public function markAsCompleted(?User $user = null): void
    {
        $this->update(['completed_at' => now()]);

        // Log activity
        $this->activities()->create([
            'user_id' => $user ? $user->id : optional(Auth::user())->id,
            'action' => 'completed',
            'description' => 'Task marked as completed',
        ]);
    }

    /**
     * Mark a task as incomplete.
     *
     * @param  User|null  $user  The user reopening the task
     */
    public function markAsIncomplete(?User $user = null): void
    {
        $this->update(['completed_at' => null]);

        // Log activity
        $this->activities()->create([
            'user_id' => $user ? $user->id : optional(Auth::user())->id,
            'action' => 'reopened',
            'description' => 'Task marked as incomplete',
        ]);
    }

    /**
     * Move a task to a different column.
     *
     * @param  int|string  $columnId
     */
    public function moveToColumn($columnId, int $position, ?User $user = null): bool
    {
        $oldColumnId = $this->column_id;
        $oldPosition = $this->position;

        // If not changing columns, just update position
        if ($oldColumnId == $columnId) {
            return $this->movePosition($position, $user);
        }

        // Update positions in the old column
        Task::where('column_id', $oldColumnId)
            ->where('position', '>', $oldPosition)
            ->decrement('position');

        // Make space in the new column
        Task::where('column_id', $columnId)
            ->where('position', '>=', $position)
            ->increment('position');

        // Update task with new column and position
        $this->update([
            'column_id' => $columnId,
            'position' => $position,
        ]);

        // Log activity
        $newColumn = BoardColumn::find($columnId);
        $this->activities()->create([
            'user_id' => $user ? $user->id : optional(Auth::user())->id,
            'action' => 'moved',
            'description' => "Task moved to {$newColumn->name}",
            'old_values' => ['column_id' => $oldColumnId, 'position' => $oldPosition],
            'new_values' => ['column_id' => $columnId, 'position' => $position],
        ]);

        return true;
    }

    /**
     * Move a task to a different position in the same column.
     */
    public function movePosition(int $newPosition, ?User $user = null): bool
    {
        $oldPosition = $this->position;

        // If position didn't change, do nothing
        if ($oldPosition === $newPosition) {
            return true;
        }

        // Moving up (lower position number)
        if ($newPosition < $oldPosition) {
            Task::where('column_id', $this->column_id)
                ->whereBetween('position', [$newPosition, $oldPosition - 1])
                ->increment('position');
        }
        // Moving down (higher position number)
        else {
            Task::where('column_id', $this->column_id)
                ->whereBetween('position', [$oldPosition + 1, $newPosition])
                ->decrement('position');
        }

        // Update task position
        $this->update(['position' => $newPosition]);

        // Log activity
        $this->activities()->create([
            'user_id' => $user ? $user->id : optional(Auth::user())->id,
            'action' => 'reordered',
            'description' => 'Task position changed',
            'old_values' => ['position' => $oldPosition],
            'new_values' => ['position' => $newPosition],
        ]);

        return true;
    }

    /**
     * Assign a task to a user.
     */
    public function assignTo(User $assignee, ?User $assigner = null): void
    {
        $oldAssigneeId = $this->assignee_id;
        $this->update(['assignee_id' => $assignee->id]);

        // Log activity
        $this->activities()->create([
            'user_id' => $assigner ? $assigner->id : optional(Auth::user())->id,
            'action' => 'assigned',
            'description' => "Task assigned to {$assignee->name}",
            'old_values' => ['assignee_id' => $oldAssigneeId],
            'new_values' => ['assignee_id' => $assignee->id],
        ]);
    }

    /**
     * Unassign a task.
     */
    public function unassign(?User $actor = null): void
    {
        $oldAssigneeId = $this->assignee_id;
        $this->update(['assignee_id' => null]);

        // Log activity
        $this->activities()->create([
            'user_id' => $actor ? $actor->id : optional(Auth::user())->id,
            'action' => 'unassigned',
            'description' => 'Task unassigned',
            'old_values' => ['assignee_id' => $oldAssigneeId],
            'new_values' => ['assignee_id' => null],
        ]);
    }

    /**
     * Check if a task can be edited by a user.
     */
    public function canBeEditedBy(User $user): bool
    {
        return $this->board->canUserAccess($user);
    }

    /**
     * Check if a task can be deleted by a user.
     */
    public function canBeDeletedBy(User $user): bool
    {
        return $this->board->canUserManage($user) ||
               $this->created_by === $user->id;
    }

    /**
     * Boot the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        // Set default position when creating a task
        static::creating(function ($task) {
            if (! isset($task->position)) {
                $task->position = Task::where('column_id', $task->column_id)->count();
            }

            if (! isset($task->created_by) && Auth::check()) {
                $task->created_by = Auth::id();
            }

            // Inherit the board's project and mint an immutable issue key.
            // Covers every creation path (store, duplicate, seeding).
            if (! $task->project_id && $task->board_id) {
                $task->project_id = Board::whereKey($task->board_id)->value('project_id');
            }

            if (! $task->issue_key && $task->project_id) {
                $project = Project::find($task->project_id);
                if ($project) {
                    $task->issue_key = $project->nextIssueKey();
                }
            }
        });

        // Update positions when deleting a task
        static::deleting(function ($task) {
            Task::where('column_id', $task->column_id)
                ->where('position', '>', $task->position)
                ->decrement('position');

            // Log deletion activity if not a soft delete
            if (! $task->isForceDeleting()) {
                $task->activities()->create([
                    'user_id' => optional(Auth::user())->id,
                    'action' => 'deleted',
                    'description' => 'Task was deleted',
                ]);
            }
        });
    }
}
