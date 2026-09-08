<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            // A validated slug (e.g. backlinks, guest_posts); not an enum so packages can evolve.
            $table->string('target_key', 64);
            $table->string('label', 100);
            $table->unsignedInteger('target_value');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['package_id', 'target_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_targets');
    }
};
