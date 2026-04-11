<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('task_comments')) {
            Schema::table('task_comments', function (Blueprint $table) {
                $table->index(['task_id', 'created_at'], 'task_comments_task_created_idx');
            });
        }

        if (Schema::hasTable('team_members')) {
            Schema::table('team_members', function (Blueprint $table) {
                $table->index(['team_id', 'user_id'], 'team_members_team_user_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('task_comments')) {
            Schema::table('task_comments', function (Blueprint $table) {
                $table->dropIndex('task_comments_task_created_idx');
            });
        }

        if (Schema::hasTable('team_members')) {
            Schema::table('team_members', function (Blueprint $table) {
                $table->dropIndex('team_members_team_user_idx');
            });
        }
    }
};
