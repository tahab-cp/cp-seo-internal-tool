<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Historical ranking observations. Movement is always derived from
        // consecutive snapshots; there are deliberately no previous_position
        // or movement columns.
        Schema::create('ranking_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('keyword_id')->constrained()->restrictOnDelete();
            $table->foreignId('monthly_cycle_id')->constrained()->restrictOnDelete();
            $table->dateTime('checked_at');
            // NULL means "not ranking"; 0 is never valid.
            $table->unsignedInteger('position')->nullable();
            $table->string('ranking_url', 500)->nullable();
            // String-backed App\Enums\RankingSource.
            $table->string('source', 32);
            $table->timestamp('created_at')->nullable();

            // One observation per keyword, moment and source.
            $table->unique(['keyword_id', 'checked_at', 'source']);
            $table->index(['monthly_cycle_id', 'keyword_id']);
            $table->index(['keyword_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ranking_snapshots');
    }
};
