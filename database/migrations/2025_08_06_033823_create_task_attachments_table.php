<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Only create if it doesn't exist
        if (!Schema::hasTable('task_attachments')) {
            Schema::create('task_attachments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('task_id')->constrained()->onDelete('cascade');
                $table->foreignId('uploaded_by')->constrained('users')->onDelete('cascade');
                $table->string('filename');
                $table->string('original_name');
                $table->string('file_path');
                $table->bigInteger('file_size');
                $table->string('mime_type');
                $table->timestamps();

                $table->index(['task_id']);
                $table->index(['uploaded_by']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('task_attachments');
    }
};
