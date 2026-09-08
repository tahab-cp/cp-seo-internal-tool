<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            // Master data is project history: never cascade away.
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->string('url', 500);
            $table->string('path', 500)->nullable();
            $table->string('title')->nullable();
            // A plain string in V1; no page-type taxonomy yet.
            $table->string('page_type', 100)->nullable();
            // String-backed App\Enums\PageStatus; never a MySQL ENUM.
            $table->string('status', 32)->index();
            $table->timestamps();
            $table->softDeletes();

            // Unique within a project only; other projects may hold the same URL.
            $table->unique(['project_id', 'url']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
