<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Project extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'team_id',
        'name',
        'key',
        'description',
        'lead_user_id',
    ];

    protected $casts = [
        // Encrypt sensitive project data (same pattern as Team.description)
        'description' => 'encrypted',
        'issue_counter' => 'integer',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class)->withDefault();
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_user_id');
    }

    public function boards(): HasMany
    {
        return $this->hasMany(Board::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function labels(): HasMany
    {
        return $this->hasMany(Label::class)->orderBy('name');
    }

    public function statuses(): HasMany
    {
        return $this->hasMany(Status::class)->orderBy('position');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(Transition::class);
    }

    /**
     * New projects start with the standard trio and an open workflow
     * (wildcard transition into every status) — restricting is opt-in.
     */
    protected static function booted(): void
    {
        static::created(function (Project $project) {
            $project->seedDefaultWorkflow();
        });
    }

    public function seedDefaultWorkflow(): void
    {
        if ($this->statuses()->exists()) {
            return;
        }

        $defaults = [
            ['name' => 'To Do', 'category' => 'todo', 'position' => 0, 'is_default' => true],
            ['name' => 'In Progress', 'category' => 'in_progress', 'position' => 1, 'is_default' => false],
            ['name' => 'Done', 'category' => 'done', 'position' => 2, 'is_default' => false],
        ];

        foreach ($defaults as $attributes) {
            $status = $this->statuses()->create($attributes);
            $this->transitions()->create(['from_status_id' => null, 'to_status_id' => $status->id]);
        }
    }

    /**
     * The user's effective role in this project (personal projects: lead
     * acts as owner).
     */
    public function userRole(User $user): ?string
    {
        if (! $this->team_id) {
            return $this->lead_user_id === $user->id ? 'owner' : null;
        }

        return $this->team->getUserRole($user);
    }

    public function allowsTransition(?int $fromStatusId, int $toStatusId, ?string $role): bool
    {
        return $this->transitions()
            ->where('to_status_id', $toStatusId)
            ->where(function ($q) use ($fromStatusId) {
                $q->whereNull('from_status_id');
                if ($fromStatusId) {
                    $q->orWhere('from_status_id', $fromStatusId);
                }
            })
            ->get()
            ->contains(fn (Transition $t) => $t->roleAllowed($role));
    }

    /**
     * Transitions the given role may take from a status (wildcards included).
     */
    public function availableTransitionsFor(?int $fromStatusId, ?string $role)
    {
        return $this->transitions()
            ->with('toStatus')
            ->where(function ($q) use ($fromStatusId) {
                $q->whereNull('from_status_id');
                if ($fromStatusId) {
                    $q->orWhere('from_status_id', $fromStatusId);
                }
            })
            ->get()
            ->filter(fn (Transition $t) => $t->roleAllowed($role) && $t->to_status_id !== $fromStatusId)
            ->unique('to_status_id')
            ->values();
    }

    /**
     * Reserve and return the next issue key (e.g. "TF-42").
     *
     * Runs in its own transaction with a row lock so concurrent task
     * creations can never mint the same key. Keys are never reused.
     */
    public function nextIssueKey(): string
    {
        return DB::transaction(function () {
            $fresh = self::query()->whereKey($this->id)->lockForUpdate()->firstOrFail();
            $fresh->issue_counter++;
            $fresh->save();

            $this->issue_counter = $fresh->issue_counter;

            return $fresh->key.'-'.$fresh->issue_counter;
        });
    }

    /**
     * Scope projects visible to a user: personal projects they lead,
     * plus projects of teams they belong to.
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where(function ($q) use ($userId) {
            $q->where(function ($personal) use ($userId) {
                $personal->whereNull('team_id')->where('lead_user_id', $userId);
            })->orWhereHas('team', function ($teamQuery) use ($userId) {
                $teamQuery->forUser($userId);
            });
        });
    }

    /**
     * Find (or lazily create) the default project for a team or, when
     * teamless, the owner's personal project. Used when boards are created
     * without an explicit project.
     */
    public static function resolveDefaultProjectId(?int $teamId, ?int $userId): ?int
    {
        if ($teamId) {
            $existing = self::where('team_id', $teamId)->orderBy('id')->value('id');
            if ($existing) {
                return $existing;
            }

            $team = Team::find($teamId);
            if (! $team) {
                return null;
            }

            return self::create([
                'team_id' => $team->id,
                'name' => $team->name,
                'key' => self::generateUniqueKey(self::deriveKeyFromName($team->name)),
                'lead_user_id' => $team->owner_id,
            ])->id;
        }

        if (! $userId) {
            return null;
        }

        $existing = self::whereNull('team_id')->where('lead_user_id', $userId)->orderBy('id')->value('id');
        if ($existing) {
            return $existing;
        }

        return self::create([
            'team_id' => null,
            'name' => 'Personal Project',
            'key' => self::generateUniqueKey('PSN'),
            'lead_user_id' => $userId,
        ])->id;
    }

    /**
     * Derive a project key from a name: initials, uppercase, 2–5 chars.
     */
    public static function deriveKeyFromName(string $name): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $key = '';
        foreach ($words as $word) {
            $key .= strtoupper($word[0]);
        }
        $key = preg_replace('/[^A-Z]/', '', $key) ?? '';

        if (strlen($key) < 2) {
            $letters = strtoupper(preg_replace('/[^A-Za-z]/', '', $name) ?? '');
            $key = substr($letters.'PRJ', 0, 3);
        }

        return substr($key, 0, 5);
    }

    /**
     * Ensure a key is globally unique by appending a numeric suffix.
     */
    public static function generateUniqueKey(string $base): string
    {
        $key = $base;
        $suffix = 2;
        while (self::withTrashed()->where('key', $key)->exists()) {
            $key = substr($base, 0, 5).$suffix;
            $suffix++;
        }

        return $key;
    }

    public function isMember(User $user): bool
    {
        if (! $this->team_id) {
            return $this->lead_user_id === $user->id;
        }

        return $this->team->isMember($user);
    }

    public function canManage(User $user): bool
    {
        if (! $this->team_id) {
            return $this->lead_user_id === $user->id;
        }

        return $this->team->isAdmin($user) || $this->lead_user_id === $user->id;
    }

    public function canEdit(User $user): bool
    {
        if (! $this->team_id) {
            return $this->lead_user_id === $user->id;
        }

        return $this->team->canEditTasks($user);
    }
}
