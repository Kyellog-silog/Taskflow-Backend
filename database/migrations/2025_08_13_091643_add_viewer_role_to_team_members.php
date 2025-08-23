<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        
        if ($driver === 'pgsql') {
            // PostgreSQL specific constraints
            DB::statement("ALTER TABLE team_members DROP CONSTRAINT IF EXISTS team_members_role_check");
            DB::statement("ALTER TABLE team_members ALTER COLUMN role TYPE varchar(255)");
            DB::statement("ALTER TABLE team_members ADD CONSTRAINT team_members_role_check CHECK (role IN ('admin', 'member', 'viewer'))");
        } elseif ($driver === 'sqlite') {
            // SQLite doesn't support check constraints modification easily
            // We'll just ensure the column can accept the new value
            // The application logic will handle validation
        } else {
            // MySQL and other databases
            Schema::table('team_members', function (Blueprint $table) {
                $table->string('role')->default('member')->change();
            });
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        
        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE team_members DROP CONSTRAINT IF EXISTS team_members_role_check");
            DB::statement("ALTER TABLE team_members ADD CONSTRAINT team_members_role_check CHECK (role IN ('admin', 'member'))");
        }
        // For SQLite and others, no action needed as we rely on application validation
    }
};
