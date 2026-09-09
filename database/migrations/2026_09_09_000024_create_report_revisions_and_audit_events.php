<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Controlled corrections of finalized reports: explicit report versions,
 * immutable archived revisions (snapshot + PDF of every superseded final)
 * and a focused audit trail for finalize / unlock events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monthly_reports', function (Blueprint $table) {
            // The version this report represents (final) or is preparing
            // (draft / ready). Existing finals are version 1.
            $table->unsignedInteger('version')->default(1)->after('status');
        });

        Schema::create('monthly_report_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monthly_report_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('snapshot_json');
            $table->string('generated_pdf_path', 500)->nullable();
            $table->dateTime('generated_at')->nullable();
            $table->dateTime('finalized_at');
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('archived_at');
            $table->foreignId('archived_by')->constrained('users')->restrictOnDelete();
            $table->string('unlock_reason', 1000);
            $table->timestamp('created_at')->nullable();

            $table->unique(['monthly_report_id', 'version']);
        });

        Schema::create('monthly_cycle_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monthly_cycle_id')->constrained()->restrictOnDelete();
            $table->foreignId('monthly_report_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('event_type', 64);
            $table->string('reason', 1000)->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['monthly_cycle_id', 'created_at']);
            $table->index(['monthly_report_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_cycle_audit_events');
        Schema::dropIfExists('monthly_report_revisions');

        Schema::table('monthly_reports', function (Blueprint $table) {
            $table->dropColumn('version');
        });
    }
};
