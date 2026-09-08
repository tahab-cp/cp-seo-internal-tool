<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_target_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('target_key', 64);
            $table->string('label', 100);
            $table->unsignedInteger('target_value');
            $table->timestamps();

            $table->unique(['project_id', 'target_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_target_overrides');
    }
};
