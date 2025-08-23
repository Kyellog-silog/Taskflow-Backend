<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $task_id
 * @property int $user_id
 * @property string $action
 * @property string|null $description
 * @property array<array-key, mixed>|null $old_values
 * @property array<array-key, mixed>|null $new_values
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Task $task
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TaskActivity forTask($taskId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TaskActivity latest()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TaskActivity newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TaskActivity newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TaskActivity query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TaskActivity whereAction($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TaskActivity whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TaskActivity whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TaskActivity whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TaskActivity whereNewValues($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TaskActivity whereOldValues($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TaskActivity whereTaskId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TaskActivity whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TaskActivity whereUserId($value)
 * @mixin \Eloquent
 */
class TaskActivity extends Model
{
    use HasFactory;

    protected $fillable = [
        'task_id',
        'user_id',
        'action',
        'description',
        'old_values',
        'new_values',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    protected $with = ['user'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeLatest($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    public function scopeForTask($query, $taskId)
    {
        return $query->where('task_id', $taskId);
    }
}
