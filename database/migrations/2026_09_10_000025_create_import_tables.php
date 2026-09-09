<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per CSV import attempt: the audit trail of what arrived
        // via CSV, for which project/month, and how it went.
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('monthly_cycle_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('import_type', 32);
            $table->string('original_filename');
            $table->string('stored_file_path', 500)->nullable();
            $table->json('mapping_json')->nullable();
            $table->string('status', 32);
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'created_at']);
        });

        // Reviewable validation problems (errors and warnings) per CSV row.
        // Successful rows are not copied here.
        Schema::create('import_row_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('field', 64)->nullable();
            $table->string('severity', 16);
            $table->string('message', 1000);
            $table->json('raw_row_json')->nullable();
            $table->dateTime('created_at');

            $table->index(['import_batch_id', 'row_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_row_errors');
        Schema::dropIfExists('import_batches');
    }
};
