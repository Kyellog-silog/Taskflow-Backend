<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->string('name', 100);
            // Jira-style status category — drives board grouping and reports
            $table->string('category', 20)->default('todo'); // todo | in_progress | done
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['project_id', 'position']);
        });

        Schema::create('transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            // NULL from_status_id = wildcard: allowed from any status
            $table->foreignId('from_status_id')->nullable()->constrained('statuses')->onDelete('cascade');
            $table->foreignId('to_status_id')->constrained('statuses')->onDelete('cascade');
            $table->string('name', 100)->nullable();
            // NULL/empty = any non-viewer member may use this transition
            $table->json('allowed_roles')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'from_status_id']);
        });

        // SQLite cannot add FK constraints via ALTER TABLE — plain columns +
        // indexes everywhere, real constraints on pgsql (same pattern as Phase 1)
        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('status_id')->nullable();
            $table->index('status_id');
        });

        Schema::table('board_columns', function (Blueprint $table) {
            $table->unsignedBigInteger('status_id')->nullable();
            $table->index('status_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_status_id_foreign FOREIGN KEY (status_id) REFERENCES statuses(id) ON DELETE SET NULL');
            DB::statement('ALTER TABLE board_columns ADD CONSTRAINT board_columns_status_id_foreign FOREIGN KEY (status_id) REFERENCES statuses(id) ON DELETE SET NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_status_id_foreign');
            DB::statement('ALTER TABLE board_columns DROP CONSTRAINT IF EXISTS board_columns_status_id_foreign');
        }

        Schema::table('board_columns', function (Blueprint $table) {
            $table->dropIndex(['status_id']);
            $table->dropColumn('status_id');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['status_id']);
            $table->dropColumn('status_id');
        });

        Schema::dropIfExists('transitions');
        Schema::dropIfExists('statuses');
    }
};
