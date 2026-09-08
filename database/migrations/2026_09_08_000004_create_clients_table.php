<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('company_name')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            // String-backed App\Enums\ClientStatus; never a MySQL ENUM.
            $table->string('status', 32)->index();
            $table->foreignId('account_manager_id')
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
        Schema::dropIfExists('clients');
    }
};
