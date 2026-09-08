<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_cycles', function (Blueprint $table) {
            $table->id();
            // Reporting history must survive: a project with cycles can never be hard-deleted.
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            // String-backed App\Enums\MonthlyCycleStatus; never a MySQL ENUM.
            $table->string('status', 32)->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('locked_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'year', 'month']);
            $table->index(['year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_cycles');
    }
};
