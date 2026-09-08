<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly narrative notes, per-project report configuration, and the
 * monthly report with its snapshotted section configuration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monthly_cycle_id')->constrained()->restrictOnDelete();
            $table->string('type', 32);
            $table->string('title')->nullable();
            $table->text('body');
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['monthly_cycle_id', 'type', 'sort_order']);
        });

        // The project's template for FUTURE reports.
        Schema::create('project_report_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('section_key', 64);
            $table->string('title', 120);
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_required')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['project_id', 'section_key']);
        });

        Schema::create('monthly_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monthly_cycle_id')->constrained()->restrictOnDelete();
            $table->string('status', 32);
            $table->text('executive_summary')->nullable();
            $table->text('review_notes')->nullable();
            $table->json('snapshot_json')->nullable();
            $table->string('generated_pdf_path', 500)->nullable();
            $table->dateTime('generated_at')->nullable();
            $table->dateTime('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('monthly_cycle_id');
        });

        // Snapshot of project_report_sections taken when the report is created.
        Schema::create('monthly_report_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monthly_report_id')->constrained()->cascadeOnDelete();
            $table->string('section_key', 64);
            $table->string('title', 120);
            $table->boolean('is_enabled');
            $table->boolean('is_required');
            $table->string('status', 32);
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('custom_text')->nullable();
            $table->json('settings_json')->nullable();
            $table->timestamps();

            $table->unique(['monthly_report_id', 'section_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_report_sections');
        Schema::dropIfExists('monthly_reports');
        Schema::dropIfExists('project_report_sections');
        Schema::dropIfExists('monthly_notes');
    }
};
