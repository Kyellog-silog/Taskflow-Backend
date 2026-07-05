<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Every project gets statuses derived from its boards' existing column
     * names, columns are mapped to those statuses, tasks inherit the status
     * of their column, and a fully-open wildcard workflow is seeded so
     * nothing is restricted until a user edits the workflow.
     */
    public function up(): void
    {
        $projects = DB::table('projects')->orderBy('id')->get(['id']);

        foreach ($projects as $project) {
            $boardIds = DB::table('boards')->where('project_id', $project->id)->pluck('id');

            // Distinct column names across the project's boards, in board order
            $columns = $boardIds->isEmpty()
                ? collect()
                : DB::table('board_columns')->whereIn('board_id', $boardIds)->orderBy('position')->get(['id', 'name']);

            $statusIdByName = [];
            $position = 0;

            foreach ($columns as $column) {
                $nameKey = mb_strtolower(trim($column->name));
                if (! isset($statusIdByName[$nameKey])) {
                    $statusIdByName[$nameKey] = DB::table('statuses')->insertGetId([
                        'project_id' => $project->id,
                        'name' => trim($column->name) ?: 'To Do',
                        'category' => $this->guessCategory($column->name),
                        'position' => $position++,
                        'is_default' => false,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('board_columns')->where('id', $column->id)->update([
                    'status_id' => $statusIdByName[$nameKey],
                ]);
            }

            // Projects without any columns get the standard trio
            if (empty($statusIdByName)) {
                foreach ([['To Do', 'todo'], ['In Progress', 'in_progress'], ['Done', 'done']] as $i => [$name, $category]) {
                    $statusIdByName[mb_strtolower($name)] = DB::table('statuses')->insertGetId([
                        'project_id' => $project->id,
                        'name' => $name,
                        'category' => $category,
                        'position' => $i,
                        'is_default' => false,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // Mark the first todo-category status (or the first status) as default
            $defaultId = DB::table('statuses')
                ->where('project_id', $project->id)
                ->orderByRaw("case when category = 'todo' then 0 else 1 end")
                ->orderBy('position')
                ->value('id');
            if ($defaultId) {
                DB::table('statuses')->where('id', $defaultId)->update(['is_default' => true]);
            }

            // Tasks inherit their column's status
            foreach ($columns as $column) {
                DB::table('tasks')->where('column_id', $column->id)->update([
                    'status_id' => $statusIdByName[mb_strtolower(trim($column->name))],
                ]);
            }

            // Open workflow: wildcard transition into every status
            foreach ($statusIdByName as $statusId) {
                DB::table('transitions')->insert([
                    'project_id' => $project->id,
                    'from_status_id' => null,
                    'to_status_id' => $statusId,
                    'name' => null,
                    'allowed_roles' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('tasks')->update(['status_id' => null]);
        DB::table('board_columns')->update(['status_id' => null]);
        DB::table('transitions')->delete();
        DB::table('statuses')->delete();
    }

    private function guessCategory(string $name): string
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
};
