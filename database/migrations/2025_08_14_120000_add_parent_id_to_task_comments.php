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
                if (!Schema::hasColumn('task_comments', 'parent_id')) {
                    $table->foreignId('parent_id')->nullable()->constrained('task_comments')->onDelete('cascade');
                    $table->index('parent_id');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('task_comments')) {
            Schema::table('task_comments', function (Blueprint $table) {
                if (Schema::hasColumn('task_comments', 'parent_id')) {
                    $table->dropForeign(['parent_id']);
                    $table->dropColumn('parent_id');
                }
            });
        }
    }
};
