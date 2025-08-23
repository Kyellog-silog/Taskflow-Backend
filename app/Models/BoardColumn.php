<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BoardColumn extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'board_id',
        'name',
        'position',
        'color',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'position' => 'integer',
    ];

    /**
     * Get the board that owns the column.
     *
     * @return BelongsTo
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    /**
     * Get the tasks for the column, ordered by position.
     * Explicitly specify the foreign key to avoid Laravel's default assumption.
     *
     * @return HasMany
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'column_id', 'id')->orderBy('position');
    }

    /**
     * Get only active tasks for the column.
     *
     * @return HasMany
     */
    public function activeTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'column_id', 'id')
            ->whereNull('deleted_at')
            ->orderBy('position');
    }

    /**
     * Count the tasks in this column.
     *
     * @return int
     */
    public function getTaskCountAttribute(): int
    {
        return $this->tasks()->count();
    }

    /**
     * Check if this column has reached its maximum task limit.
     *
     * @param int|null $maxTasks
     * @return bool
     */
    public function isAtCapacity(?int $maxTasks = null): bool
    {
        if ($maxTasks === null) {
            // Check if column has a max_tasks property, if not return false
            $maxTasks = $this->max_tasks ?? 0;
            if ($maxTasks <= 0) {
                return false;
            }
        }

        return $this->tasks()->count() >= $maxTasks;
    }

    /**
     * Scope a query to order columns by position.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('position');
    }

    /**
     * Reorder task positions when a task is moved or removed.
     *
     * @return void
     */
    public function reorderTasks(): void
    {
        $tasks = $this->tasks()->orderBy('position')->get();
        
        foreach ($tasks as $index => $task) {
            $task->update(['position' => $index]);
        }
    }

    /**
     * Move this column to a new position and adjust other columns.
     *
     * @param int $newPosition
     * @return bool
     */
    public function moveTo(int $newPosition): bool
    {
        if ($newPosition < 0) {
            return false;
        }

        $oldPosition = $this->position;
        
        // Get all columns from the same board
        $columns = $this->board->columns()->orderBy('position')->get();
        $maxPosition = $columns->count() - 1;
        
        // Ensure new position is valid
        $newPosition = min($newPosition, $maxPosition);
        
        // If position doesn't change, do nothing
        if ($oldPosition === $newPosition) {
            return true;
        }
        
        // Update positions of all affected columns
        if ($oldPosition < $newPosition) {
            // Moving right: decrement positions of columns between old and new
            foreach ($columns as $column) {
                if ($column->position > $oldPosition && $column->position <= $newPosition) {
                    $column->update(['position' => $column->position - 1]);
                }
            }
        } else {
            // Moving left: increment positions of columns between new and old
            foreach ($columns as $column) {
                if ($column->position >= $newPosition && $column->position < $oldPosition) {
                    $column->update(['position' => $column->position + 1]);
                }
            }
        }
        
        // Update this column's position
        $this->update(['position' => $newPosition]);
        
        return true;
    }
}