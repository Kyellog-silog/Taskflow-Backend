<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            // Index for unread count query: user_id + read_at IS NULL  
            if (!$this->hasIndex('notifications', 'notifications_user_unread_idx')) {
                $table->index(['user_id', 'read_at'], 'notifications_user_unread_idx');
            }
            
            // Skip notifications_user_created_idx as it already exists
        });

        Schema::table('teams', function (Blueprint $table) {
            // Index for team owner queries
            if (!$this->hasIndex('teams', 'teams_owner_idx')) {
                $table->index('owner_id', 'teams_owner_idx');
            }
        });

        if (Schema::hasTable('team_user')) {
            Schema::table('team_user', function (Blueprint $table) {
                // Compound index for team membership queries
                if (!$this->hasIndex('team_user', 'team_user_team_user_idx')) {
                    $table->index(['team_id', 'user_id'], 'team_user_team_user_idx');
                }
                if (!$this->hasIndex('team_user', 'team_user_user_team_idx')) {
                    $table->index(['user_id', 'team_id'], 'team_user_user_team_idx');
                }
            });
        }

        Schema::table('boards', function (Blueprint $table) {
            // Index for team boards query
            if (!$this->hasIndex('boards', 'boards_team_active_idx')) {
                $table->index(['team_id', 'deleted_at'], 'boards_team_active_idx');
            }
        });

        Schema::table('tasks', function (Blueprint $table) {
            // Index for task count by board query
            if (!$this->hasIndex('tasks', 'tasks_board_active_idx')) {
                $table->index(['board_id', 'deleted_at'], 'tasks_board_active_idx');
            }
        });
    }

    /**
     * Check if an index exists
     */
    private function hasIndex(string $table, string $indexName): bool
    {
        try {
            $connection = Schema::getConnection();
            $result = $connection->select("SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ?", [$table, $indexName]);
            return !empty($result);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            if ($this->hasIndex('notifications', 'notifications_user_unread_idx')) {
                $table->dropIndex('notifications_user_unread_idx');
            }
        });

        Schema::table('teams', function (Blueprint $table) {
            if ($this->hasIndex('teams', 'teams_owner_idx')) {
                $table->dropIndex('teams_owner_idx');
            }
        });

        if (Schema::hasTable('team_user')) {
            Schema::table('team_user', function (Blueprint $table) {
                if ($this->hasIndex('team_user', 'team_user_team_user_idx')) {
                    $table->dropIndex('team_user_team_user_idx');
                }
                if ($this->hasIndex('team_user', 'team_user_user_team_idx')) {
                    $table->dropIndex('team_user_user_team_idx');
                }
            });
        }

        Schema::table('boards', function (Blueprint $table) {
            if ($this->hasIndex('boards', 'boards_team_active_idx')) {
                $table->dropIndex('boards_team_active_idx');
            }
        });

        Schema::table('tasks', function (Blueprint $table) {
            if ($this->hasIndex('tasks', 'tasks_board_active_idx')) {
                $table->dropIndex('tasks_board_active_idx');
            }
        });
    }
};
