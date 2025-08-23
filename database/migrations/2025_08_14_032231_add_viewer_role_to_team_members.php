<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('team_members', function (Blueprint $table) {
            // Update the role column to include 'viewer' as an option
            // We'll do this by modifying the existing role column
            $table->string('role')->default('member')->change();
        });
        
        // Update existing records to ensure they have valid roles
        DB::statement("UPDATE team_members SET role = 'member' WHERE role NOT IN ('admin', 'member', 'viewer')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('team_members', function (Blueprint $table) {
            // Revert back to original state if needed
            $table->string('role')->default('member')->change();
        });
    }
};
