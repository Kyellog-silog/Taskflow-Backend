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
        Schema::create('team_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->onDelete('cascade');
            $table->foreignId('invited_by')->constrained('users')->onDelete('cascade');
            $table->string('email');
            $table->uuid('token')->unique();
            $table->string('role')->default('member');
            $table->string('status')->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            
            // Add constraints for the role and status fields
            $table->index(['token', 'status']);
            $table->index(['email', 'team_id']);
        });
        
        $driver = DB::getDriverName();
        
        if ($driver === 'pgsql') {
            // Add check constraints for PostgreSQL
            DB::statement("ALTER TABLE team_invitations ADD CONSTRAINT team_invitations_role_check CHECK (role IN ('admin', 'member', 'viewer'))");
            DB::statement("ALTER TABLE team_invitations ADD CONSTRAINT team_invitations_status_check CHECK (status IN ('pending', 'accepted', 'rejected', 'expired'))");
        }
        // For SQLite and other databases, we rely on application-level validation
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('team_invitations');
    }
};
