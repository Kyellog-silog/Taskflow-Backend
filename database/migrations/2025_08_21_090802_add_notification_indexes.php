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
            // Critical index for the main notifications query
            // WHERE user_id = ? ORDER BY created_at DESC
            $table->index(['user_id', 'created_at'], 'notifications_user_created_idx');
            
            // Index for unread count queries
            // WHERE user_id = ? AND read_at IS NULL
            $table->index(['user_id', 'read_at'], 'notifications_user_read_idx');
            
            // Optional: Index for notification type filtering if needed
            $table->index(['user_id', 'type'], 'notifications_user_type_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_user_created_idx');
            $table->dropIndex('notifications_user_read_idx');
            $table->dropIndex('notifications_user_type_idx');
        });
    }
};
