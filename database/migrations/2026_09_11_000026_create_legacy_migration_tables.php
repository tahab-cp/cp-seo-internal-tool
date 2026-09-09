<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Manifest of every legacy-migration run (dry runs included), so a
        // source workbook is recognised by checksum on reruns.
        Schema::create('legacy_migration_runs', function (Blueprint $table) {
            $table->id();
            $table->string('source_identifier');
            $table->string('source_filename');
            $table->string('source_checksum', 64);
            $table->string('mapper_version', 32);
            $table->string('mode', 16);
            $table->string('status', 32);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('created_items')->default(0);
            $table->unsignedInteger('updated_items')->default(0);
            $table->unsignedInteger('skipped_items')->default(0);
            $table->unsignedInteger('conflict_items')->default(0);
            $table->unsignedInteger('warning_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->json('metadata_json')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['source_checksum', 'mode']);
        });

        // Reviewable warnings, errors and conflicts per source row.
        Schema::create('legacy_migration_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legacy_migration_run_id')->constrained()->cascadeOnDelete();
            $table->string('source_sheet', 100);
            $table->unsignedInteger('source_row')->nullable();
            $table->string('severity', 16);
            $table->string('entity_type', 64)->nullable();
            $table->string('message', 1000);
            $table->json('raw_data_json')->nullable();
            $table->dateTime('created_at');

            $table->index(['legacy_migration_run_id', 'severity']);
        });

        // Ledger of what a source row became, keyed by a deterministic
        // identity fingerprint, so reruns skip records without a natural
        // database identity (backlinks, content, tasks, notes).
        Schema::create('legacy_migration_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legacy_migration_run_id')->constrained()->cascadeOnDelete();
            $table->string('source_identifier');
            $table->string('source_sheet', 100);
            $table->unsignedInteger('source_row')->nullable();
            $table->string('fingerprint', 64);
            $table->string('entity_type', 64);
            $table->unsignedBigInteger('entity_id');
            $table->dateTime('created_at');

            $table->unique(['source_identifier', 'source_sheet', 'fingerprint'], 'legacy_migration_records_identity');
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_migration_records');
        Schema::dropIfExists('legacy_migration_issues');
        Schema::dropIfExists('legacy_migration_runs');
    }
};
