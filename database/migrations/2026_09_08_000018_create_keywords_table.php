<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keywords', function (Blueprint $table) {
            $table->id();
            // Master data is project history: never cascade away.
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            // Stored exactly as typed; the *_normalized shadows exist only for uniqueness.
            $table->string('keyword');
            $table->string('keyword_normalized');
            $table->foreignId('target_page_id')->nullable()->constrained('pages')->nullOnDelete();
            $table->string('keyword_role', 32)->nullable();
            $table->unsignedInteger('search_volume')->nullable();
            $table->unsignedInteger('keyword_difficulty')->nullable();
            $table->string('search_intent', 32)->nullable();
            $table->string('location')->nullable();
            // '' when location is null so NULLs cannot bypass the unique index.
            $table->string('location_normalized')->default('');
            $table->boolean('is_branded')->default(false);
            // String-backed App\Enums\KeywordStatus; never a MySQL ENUM.
            $table->string('status', 32)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['project_id', 'keyword_normalized', 'location_normalized'], 'keywords_project_keyword_location_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keywords');
    }
};
