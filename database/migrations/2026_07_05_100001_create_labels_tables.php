<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->string('name', 50);
            $table->string('color', 7)->default('#6B7280');
            $table->timestamps();

            $table->unique(['project_id', 'name']);
        });

        Schema::create('label_task', function (Blueprint $table) {
            $table->foreignId('label_id')->constrained()->onDelete('cascade');
            $table->foreignId('task_id')->constrained()->onDelete('cascade');

            $table->primary(['label_id', 'task_id']);
            $table->index('task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('label_task');
        Schema::dropIfExists('labels');
    }
};
