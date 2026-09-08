<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Historical SEO work events on a page within a reporting month.
        // Several events per page and cycle are legitimate, so there is
        // deliberately no unique index on (page_id, monthly_cycle_id).
        Schema::create('page_optimizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('page_id')->constrained()->restrictOnDelete();
            $table->foreignId('monthly_cycle_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('optimized_at');
            $table->boolean('meta_title_updated')->default(false);
            $table->boolean('meta_description_updated')->default(false);
            $table->boolean('content_updated')->default(false);
            $table->boolean('internal_links_updated')->default(false);
            $table->boolean('schema_updated')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['monthly_cycle_id', 'page_id']);
            $table->index(['page_id', 'optimized_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_optimizations');
    }
};
