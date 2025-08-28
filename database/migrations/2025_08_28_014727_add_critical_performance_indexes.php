<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->index('email');
        });

        Schema::table('team_members', function (Blueprint $table) {
            $table->index(['team_id', 'role']);
            $table->index(['user_id', 'role']);
        });

        Schema::table('boards', function (Blueprint $table) {
            $table->index(['team_id', 'archived_at']);
            $table->index(['created_by', 'team_id']);
            $table->index('last_visited_at');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->index(['board_id', 'column_id']);
            $table->index(['assignee_id', 'completed_at']);
            $table->index(['due_date', 'completed_at']);
        });

        Schema::table('board_columns', function (Blueprint $table) {
            $table->index(['board_id', 'position']);
        });

        if (Schema::hasTable('task_comments')) {
            Schema::table('task_comments', function (Blueprint $table) {
                $table->index(['task_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['email']);
        });

        Schema::table('team_members', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'role']);
            $table->dropIndex(['user_id', 'role']);
        });

        Schema::table('boards', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'archived_at']);
            $table->dropIndex(['created_by', 'team_id']);
            $table->dropIndex(['last_visited_at']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['board_id', 'column_id']);
            $table->dropIndex(['assignee_id', 'completed_at']);
            $table->dropIndex(['due_date', 'completed_at']);
        });

        Schema::table('board_columns', function (Blueprint $table) {
            $table->dropIndex(['board_id', 'position']);
        });

        if (Schema::hasTable('task_comments')) {
            Schema::table('task_comments', function (Blueprint $table) {
                $table->dropIndex(['task_id', 'created_at']);
            });
        }
    }
};
