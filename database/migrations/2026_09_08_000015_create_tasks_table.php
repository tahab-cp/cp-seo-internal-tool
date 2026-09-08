<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            // Task history is part of project history: never cascade away.
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('monthly_cycle_id')->nullable()->constrained()->restrictOnDelete();
            // The source template item may disappear; the task keeps living as an operational snapshot.
            $table->foreignId('task_template_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category', 100)->nullable();
            // String-backed App\Enums\TaskStatus / TaskPriority; never MySQL ENUMs.
            $table->string('status', 32);
            $table->string('priority', 32);
            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // One template item generates at most one task per project. NULL
            // task_template_item_id (manual tasks) is unlimited: MySQL/MariaDB
            // unique indexes treat every NULL as distinct.
            $table->unique(['project_id', 'task_template_item_id']);
            $table->index(['project_id', 'status']);
            $table->index(['assigned_user_id', 'status', 'due_date']);
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
