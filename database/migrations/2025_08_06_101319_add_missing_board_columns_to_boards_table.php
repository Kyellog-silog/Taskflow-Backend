<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            // Add archived_at column if it doesn't exist
            if (!Schema::hasColumn('boards', 'archived_at')) {
                $table->timestamp('archived_at')->nullable();
            }
            
            // Add last_visited_at column if it doesn't exist
            if (!Schema::hasColumn('boards', 'last_visited_at')) {
                $table->timestamp('last_visited_at')->nullable();
            }
            
            // Add deleted_at column for soft deletes if it doesn't exist
            if (!Schema::hasColumn('boards', 'deleted_at')) {
                $table->timestamp('deleted_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            $table->dropColumn(['archived_at', 'last_visited_at', 'deleted_at']);
        });
    }
};
