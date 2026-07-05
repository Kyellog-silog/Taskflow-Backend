<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            // Nullable: personal projects (mirrors personal boards) have no team
            $table->foreignId('team_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('key', 10)->unique();
            $table->text('description')->nullable();
            $table->foreignId('lead_user_id')->constrained('users')->onDelete('cascade');
            // Monotonic counter for issue keys; never decremented, even on task deletion
            $table->unsignedInteger('issue_counter')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('lead_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
