<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly analytics feeding the reporting system. Every table hangs off a
 * MonthlyCycle (which already determines the Project). Percentages (ctr,
 * engagement_rate) are stored as human percentages: 8.50 means 8.5%.
 * average_position is NULL when unknown, never 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One Google Search Console summary per reporting month.
        Schema::create('gsc_monthly_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monthly_cycle_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('clicks');
            $table->unsignedBigInteger('impressions');
            $table->decimal('ctr', 5, 2)->nullable();
            $table->decimal('average_position', 7, 2)->nullable();
            $table->string('source', 32);
            $table->dateTime('synced_at')->nullable();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('monthly_cycle_id');
        });

        // Search-performance rows; deliberately not linked to tracked keywords.
        Schema::create('gsc_query_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monthly_cycle_id')->constrained()->restrictOnDelete();
            $table->string('query');
            $table->unsignedBigInteger('clicks');
            $table->unsignedBigInteger('impressions');
            $table->decimal('ctr', 5, 2)->nullable();
            $table->decimal('average_position', 7, 2)->nullable();
            $table->timestamps();

            $table->unique(['monthly_cycle_id', 'query']);
        });

        // Landing-page rows; page_url is the source value, page_id an optional mapping.
        Schema::create('gsc_page_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monthly_cycle_id')->constrained()->restrictOnDelete();
            $table->foreignId('page_id')->nullable()->constrained('pages')->nullOnDelete();
            $table->string('page_url', 500);
            $table->unsignedBigInteger('clicks');
            $table->unsignedBigInteger('impressions');
            $table->decimal('ctr', 5, 2)->nullable();
            $table->decimal('average_position', 7, 2)->nullable();
            $table->timestamps();

            $table->unique(['monthly_cycle_id', 'page_url']);
        });

        // One Google Analytics 4 summary per reporting month.
        Schema::create('ga4_monthly_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monthly_cycle_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('active_users')->nullable();
            $table->unsignedBigInteger('new_users')->nullable();
            $table->unsignedBigInteger('sessions')->nullable();
            $table->unsignedBigInteger('organic_sessions')->nullable();
            $table->unsignedBigInteger('engaged_sessions')->nullable();
            $table->decimal('engagement_rate', 5, 2)->nullable();
            $table->unsignedInteger('average_engagement_time_seconds')->nullable();
            $table->unsignedBigInteger('event_count')->nullable();
            $table->unsignedBigInteger('key_events')->nullable();
            $table->string('source', 32);
            $table->dateTime('synced_at')->nullable();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('monthly_cycle_id');
        });

        // Audience by country rows.
        Schema::create('ga4_country_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monthly_cycle_id')->constrained()->restrictOnDelete();
            $table->string('country', 100);
            $table->unsignedBigInteger('active_users')->nullable();
            $table->unsignedBigInteger('new_users')->nullable();
            $table->unsignedBigInteger('sessions')->nullable();
            $table->unsignedBigInteger('engaged_sessions')->nullable();
            $table->decimal('engagement_rate', 5, 2)->nullable();
            $table->unsignedBigInteger('event_count')->nullable();
            $table->unsignedBigInteger('key_events')->nullable();
            $table->timestamps();

            $table->unique(['monthly_cycle_id', 'country']);
        });

        // One site-authority snapshot per reporting month (vendor metrics,
        // unrelated to the operational backlinks deliverables).
        Schema::create('authority_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monthly_cycle_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('moz_domain_authority')->nullable();
            $table->unsignedBigInteger('moz_linking_root_domains')->nullable();
            $table->decimal('ahrefs_domain_rating', 5, 1)->nullable();
            $table->decimal('ahrefs_url_rating', 5, 1)->nullable();
            $table->unsignedBigInteger('backlinks_count')->nullable();
            $table->unsignedBigInteger('referring_domains_count')->nullable();
            $table->string('source', 32);
            $table->dateTime('synced_at')->nullable();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique('monthly_cycle_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authority_metrics');
        Schema::dropIfExists('ga4_country_metrics');
        Schema::dropIfExists('ga4_monthly_metrics');
        Schema::dropIfExists('gsc_page_metrics');
        Schema::dropIfExists('gsc_query_metrics');
        Schema::dropIfExists('gsc_monthly_metrics');
    }
};
