<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add soft deletes to task_comments if the model uses it
        if (Schema::hasTable('task_comments')) {
            Schema::table('task_comments', function (Blueprint $table) {
                if (!Schema::hasColumn('task_comments', 'deleted_at')) {
                    $table->softDeletes();
                }
            });
        }

        // Add soft deletes to task_attachments if the model uses it
        if (Schema::hasTable('task_attachments')) {
            Schema::table('task_attachments', function (Blueprint $table) {
                if (!Schema::hasColumn('task_attachments', 'deleted_at')) {
                    $table->softDeletes();
                }
            });
        }

        // Add soft deletes to task_activities if needed
        if (Schema::hasTable('task_activities')) {
            Schema::table('task_activities', function (Blueprint $table) {
                if (!Schema::hasColumn('task_activities', 'deleted_at')) {
                    $table->softDeletes();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('task_comments')) {
            Schema::table('task_comments', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }

        if (Schema::hasTable('task_attachments')) {
            Schema::table('task_attachments', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }

        if (Schema::hasTable('task_activities')) {
            Schema::table('task_activities', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
