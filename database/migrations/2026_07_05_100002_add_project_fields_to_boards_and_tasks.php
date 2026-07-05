<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite cannot add FK constraints via ALTER TABLE, so new FK columns are
        // plain bigints + indexes everywhere; real constraints added on pgsql below.
        Schema::table('boards', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id')->nullable()->after('team_id');
            $table->index('project_id');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id')->nullable()->after('board_id');
            $table->string('issue_key', 20)->nullable()->unique();
            $table->string('issue_type', 20)->default('task');
            $table->unsignedTinyInteger('story_points')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedBigInteger('epic_id')->nullable();

            $table->index(['project_id', 'issue_type']);
            $table->index('parent_id');
            $table->index('epic_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE boards ADD CONSTRAINT boards_project_id_foreign FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL');
            DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_project_id_foreign FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL');
            DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_parent_id_foreign FOREIGN KEY (parent_id) REFERENCES tasks(id) ON DELETE SET NULL');
            DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_epic_id_foreign FOREIGN KEY (epic_id) REFERENCES tasks(id) ON DELETE SET NULL');
        }

        // Widen priority from 3 Jira-unaware values to the 5 Jira levels.
        // The original enum is a varchar + CHECK constraint on both drivers.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_priority_check');
            DB::statement('ALTER TABLE tasks ALTER COLUMN priority TYPE VARCHAR(10)');
            DB::statement("ALTER TABLE tasks ALTER COLUMN priority SET DEFAULT 'medium'");
        } else {
            // SQLite: change() rebuilds the table, dropping the CHECK constraint
            Schema::table('tasks', function (Blueprint $table) {
                $table->string('priority', 10)->default('medium')->change();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE boards DROP CONSTRAINT IF EXISTS boards_project_id_foreign');
            DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_project_id_foreign');
            DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_parent_id_foreign');
            DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_epic_id_foreign');
        }

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'issue_type']);
            $table->dropIndex(['parent_id']);
            $table->dropIndex(['epic_id']);
            $table->dropUnique(['issue_key']);
            $table->dropColumn(['project_id', 'issue_key', 'issue_type', 'story_points', 'parent_id', 'epic_id']);
        });

        Schema::table('boards', function (Blueprint $table) {
            $table->dropIndex(['project_id']);
            $table->dropColumn('project_id');
        });
    }
};
