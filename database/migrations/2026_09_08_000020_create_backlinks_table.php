<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Monthly link-building records. Deliverable progress (backlinks,
        // guest posts) is always derived from these rows, never stored.
        Schema::create('backlinks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('monthly_cycle_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->date('published_date')->nullable();
            // No uniqueness: repeated domains/URLs are legitimate.
            $table->string('published_url', 500);
            $table->string('anchor_text')->nullable();
            $table->string('target_url', 500)->nullable();
            // String-backed App\Enums\BacklinkType / BacklinkStatus.
            $table->string('type', 32);
            $table->string('status', 32);
            // 0–100 scales.
            $table->unsignedTinyInteger('domain_authority')->nullable();
            $table->unsignedTinyInteger('domain_rating')->nullable();
            $table->unsignedTinyInteger('spam_score')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['monthly_cycle_id', 'status', 'type']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backlinks');
    }
};
