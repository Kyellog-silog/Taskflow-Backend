<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('created_by');
            $table->timestamp('last_visited_at')->nullable()->after('archived_at');
            $table->softDeletes()->after('last_visited_at');
        });
    }

    public function down(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            $table->dropColumn(['archived_at', 'last_visited_at']);
            $table->dropSoftDeletes();
        });
    }
};
