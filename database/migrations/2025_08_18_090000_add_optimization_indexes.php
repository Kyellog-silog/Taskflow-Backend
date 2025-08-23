<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('boards')) {
            Schema::table('boards', function (Blueprint $table) {
                if (!Schema::hasColumn('boards', 'archived_at')) return; // safety if older schema
                // Common filters/sorts
                $table->index(['created_by', 'team_id']);
                $table->index(['archived_at']);
                $table->index(['last_visited_at']);
            });
        }

        if (Schema::hasTable('board_columns')) {
            Schema::table('board_columns', function (Blueprint $table) {
                $table->index(['board_id', 'position']);
            });
        }

        if (Schema::hasTable('tasks')) {
            Schema::table('tasks', function (Blueprint $table) {
                // Additional helpful indexes
                $table->index(['board_id', 'column_id', 'position']);
                $table->index(['assignee_id', 'completed_at']);
                $table->index(['created_by']);
            });
        }

        if (Schema::hasTable('task_activities')) {
            Schema::table('task_activities', function (Blueprint $table) {
                $table->index(['user_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('boards')) {
            Schema::table('boards', function (Blueprint $table) {
                $table->dropIndex(['created_by', 'team_id']);
                $table->dropIndex(['archived_at']);
                $table->dropIndex(['last_visited_at']);
            });
        }

        if (Schema::hasTable('board_columns')) {
            Schema::table('board_columns', function (Blueprint $table) {
                $table->dropIndex(['board_id', 'position']);
            });
        }

        if (Schema::hasTable('tasks')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->dropIndex(['board_id', 'column_id', 'position']);
                $table->dropIndex(['assignee_id', 'completed_at']);
                $table->dropIndex(['created_by']);
            });
        }

        if (Schema::hasTable('task_activities')) {
            Schema::table('task_activities', function (Blueprint $table) {
                $table->dropIndex(['user_id', 'created_at']);
            });
        }
    }
};
