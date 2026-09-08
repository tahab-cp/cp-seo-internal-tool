<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Content operations (planning/workflow), never the article body.
        // Blog progress is derived from these rows, never stored.
        Schema::create('content_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            // NULL = project-level / unscheduled; counts toward no monthly target.
            $table->foreignId('monthly_cycle_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('target_keyword_id')->nullable()->constrained('keywords')->nullOnDelete();
            $table->string('title');
            // String-backed App\Enums\ContentType / ContentStatus.
            $table->string('content_type', 32);
            $table->string('status', 32);
            $table->date('planned_publish_date')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->string('published_url', 500)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['monthly_cycle_id', 'content_type', 'status']);
            $table->index(['project_id', 'status']);
            $table->index(['assigned_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_items');
    }
};
