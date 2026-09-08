<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            // A client with projects can never be hard-deleted; history is preserved.
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('website_url');
            $table->string('target_location')->nullable();
            // String-backed App\Enums\ProjectStatus; never a MySQL ENUM.
            $table->string('status', 32)->index();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            // Losing the owner never loses the project.
            $table->foreignId('primary_seo_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
