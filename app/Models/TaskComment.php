<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TaskComment extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'task_id',
        'user_id',
        'content',
        'parent_id',
    ];

    /**
     * The relationships that should be eager loaded.
     *
     * @var array
     */
    protected $with = ['user'];

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */
    protected $dates = ['deleted_at'];

    /**
     * Get the task that owns the comment.
     *
     * @return BelongsTo
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Parent comment (for replies)
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(TaskComment::class, 'parent_id');
    }

    /**
     * Replies to this comment
     */
    public function replies()
    {
        return $this->hasMany(TaskComment::class, 'parent_id')->with('user');
    }

    /**
     * Get the user who created the comment.
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope a query to order by most recent first.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeLatest($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Check if a user can edit this comment.
     *
     * @param User $user
     * @return bool
     */
    public function canBeEditedBy(User $user): bool
    {
        return $this->user_id === $user->id || $this->task->board->canUserManage($user);
    }

    /**
     * Check if a user can delete this comment.
     *
     * @param User $user
     * @return bool
     */
    public function canBeDeletedBy(User $user): bool
    {
        return $this->user_id === $user->id || $this->task->board->canUserManage($user);
    }

    /**
     * Get the comment content formatted as HTML.
     *
     * @return string
     */
    public function getFormattedContentAttribute(): string
    {
        // Simple formatting - replace newlines with <br> tags
        // You could implement more advanced formatting like Markdown here
        return nl2br(htmlspecialchars($this->content));
    }

    /**
     * Get a truncated version of the comment content.
     *
     * @param int $length
     * @return string
     */
    public function getExcerpt(int $length = 50): string
    {
        if (strlen($this->content) <= $length) {
            return $this->content;
        }
        
        return substr($this->content, 0, $length) . '...';
    }
}