<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Every existing team gets a default project (its boards attach to it);
     * personal boards get a per-owner personal project. Every existing task
     * gets an immutable issue key, numbered in creation order.
     */
    public function up(): void
    {
        $usedKeys = [];

        // Teams → one default project each, keyed from the team name initials
        DB::table('teams')->orderBy('id')->chunkById(100, function ($teams) use (&$usedKeys) {
            foreach ($teams as $team) {
                $projectId = DB::table('projects')->insertGetId([
                    'team_id' => $team->id,
                    'name' => $team->name,
                    'key' => $this->uniqueKey($this->deriveKey($team->name), $usedKeys),
                    'lead_user_id' => $team->owner_id,
                    'issue_counter' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('boards')->where('team_id', $team->id)->update(['project_id' => $projectId]);
            }
        });

        // Personal boards (team_id NULL) → one personal project per owner.
        // User names are encrypted at rest, so keys are generic (PSN, PSN2, ...).
        $ownerIds = DB::table('boards')->whereNull('team_id')->distinct()->pluck('created_by');
        foreach ($ownerIds as $ownerId) {
            $projectId = DB::table('projects')->insertGetId([
                'team_id' => null,
                'name' => 'Personal Project',
                'key' => $this->uniqueKey('PSN', $usedKeys),
                'lead_user_id' => $ownerId,
                'issue_counter' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('boards')
                ->whereNull('team_id')
                ->where('created_by', $ownerId)
                ->update(['project_id' => $projectId]);
        }

        // Issue keys per project, in task-creation order (soft-deleted included —
        // keys are never reused)
        $projects = DB::table('projects')->orderBy('id')->get(['id', 'key']);
        foreach ($projects as $project) {
            $boardIds = DB::table('boards')->where('project_id', $project->id)->pluck('id');
            if ($boardIds->isEmpty()) {
                continue;
            }

            $counter = 0;
            DB::table('tasks')
                ->whereIn('board_id', $boardIds)
                ->orderBy('id')
                ->chunkById(200, function ($tasks) use ($project, &$counter) {
                    foreach ($tasks as $task) {
                        $counter++;
                        DB::table('tasks')->where('id', $task->id)->update([
                            'project_id' => $project->id,
                            'issue_key' => $project->key.'-'.$counter,
                        ]);
                    }
                });

            DB::table('projects')->where('id', $project->id)->update(['issue_counter' => $counter]);
        }
    }

    public function down(): void
    {
        DB::table('tasks')->update(['project_id' => null, 'issue_key' => null]);
        DB::table('boards')->update(['project_id' => null]);
        DB::table('projects')->delete();
    }

    private function deriveKey(string $name): string
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

    private function uniqueKey(string $base, array &$usedKeys): string
    {
        $key = $base;
        $suffix = 2;
        while (isset($usedKeys[$key]) || DB::table('projects')->where('key', $key)->exists()) {
            $key = substr($base, 0, 5).$suffix;
            $suffix++;
        }
        $usedKeys[$key] = true;

        return $key;
    }
};
