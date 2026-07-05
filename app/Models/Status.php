<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Status extends Model
{
    use HasFactory;

    public const CATEGORIES = ['todo', 'in_progress', 'done'];

    protected $fillable = [
        'project_id',
        'name',
        'category',
        'position',
        'is_default',
    ];

    protected $casts = [
        'position' => 'integer',
        'is_default' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function columns(): HasMany
    {
        return $this->hasMany(BoardColumn::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Find (or create) the project status matching a board-column name —
     * keeps columns and statuses in sync as boards are created.
     */
    public static function resolveForColumn(int $projectId, string $columnName): int
    {
        $name = trim($columnName) ?: 'To Do';

        $existing = self::where('project_id', $projectId)
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->value('id');
        if ($existing) {
            return $existing;
        }

        $status = self::create([
            'project_id' => $projectId,
            'name' => $name,
            'category' => self::guessCategory($name),
            'position' => (int) self::where('project_id', $projectId)->max('position') + 1,
        ]);

        // Keep the open-workflow default: new statuses are reachable from anywhere
        Transition::create([
            'project_id' => $projectId,
            'from_status_id' => null,
            'to_status_id' => $status->id,
        ]);

        return $status->id;
    }

    public static function guessCategory(string $name): string
    {
        $n = mb_strtolower($name);

        foreach (['done', 'complete', 'closed', 'shipped', 'deployed'] as $needle) {
            if (str_contains($n, $needle)) {
                return 'done';
            }
        }
        foreach (['progress', 'doing', 'review', 'test', 'qa', 'develop', 'active'] as $needle) {
            if (str_contains($n, $needle)) {
                return 'in_progress';
            }
        }

        return 'todo';
    }
}
