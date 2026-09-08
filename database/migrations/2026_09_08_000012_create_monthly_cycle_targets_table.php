<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Historical snapshots taken when the cycle is created. Never
        // recalculated from current package/override configuration.
        Schema::create('monthly_cycle_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monthly_cycle_id')->constrained()->cascadeOnDelete();
            $table->string('target_key', 64);
            $table->string('label', 100);
            $table->unsignedInteger('target_value');
            $table->timestamps();

            $table->unique(['monthly_cycle_id', 'target_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_cycle_targets');
    }
};
