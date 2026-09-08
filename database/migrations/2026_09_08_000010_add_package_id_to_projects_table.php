<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Nullable for legacy/migrated records. A package in use by any
            // project can never be hard-deleted.
            $table->foreignId('package_id')
                ->nullable()
                ->after('client_id')
                ->constrained('packages')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('package_id');
        });
    }
};
