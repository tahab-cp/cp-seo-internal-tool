<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Guards milestone scope: only the tables delivered so far may exist.
 */
class SchemaScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_delivered_tables_exist(): void
    {
        foreach ([
            'users', 'roles', 'role_user', 'clients', 'projects', 'project_user',
            'packages', 'package_targets', 'project_target_overrides',
            'monthly_cycles', 'monthly_cycle_targets',
            'task_templates', 'task_template_items', 'tasks',
            'pages', 'page_optimizations',
            'keywords', 'ranking_snapshots',
            'backlinks', 'content_items',
            'gsc_monthly_metrics', 'gsc_query_metrics', 'gsc_page_metrics',
            'ga4_monthly_metrics', 'ga4_country_metrics', 'authority_metrics',
            'monthly_notes', 'project_report_sections', 'monthly_reports', 'monthly_report_sections',
            'monthly_report_revisions', 'monthly_cycle_audit_events',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected table [{$table}] to exist.");
        }
    }

    public function test_no_seo_domain_tables_exist_yet(): void
    {
        $future = [
            'report_snapshots', 'report_files', 'activity_log', 'csv_imports', 'analytics_syncs', 'dashboards',
        ];

        foreach ($future as $table) {
            $this->assertFalse(Schema::hasTable($table), "Table [{$table}] must not exist in this milestone.");
        }
    }

    public function test_clients_table_matches_the_documented_columns(): void
    {
        $expected = [
            'id', 'name', 'company_name', 'contact_name', 'email', 'phone', 'status',
            'account_manager_id', 'notes', 'created_at', 'updated_at', 'deleted_at',
        ];

        $this->assertEqualsCanonicalizing($expected, Schema::getColumnListing('clients'));
    }

    public function test_projects_tables_match_the_milestone_three_columns(): void
    {
        $this->assertEqualsCanonicalizing([
            'id', 'client_id', 'package_id', 'name', 'website_url', 'target_location', 'status', 'start_date',
            'end_date', 'primary_seo_user_id', 'notes', 'created_at', 'updated_at', 'deleted_at',
        ], Schema::getColumnListing('projects'));

        $this->assertEqualsCanonicalizing([
            'id', 'project_id', 'user_id', 'project_role', 'created_at', 'updated_at',
        ], Schema::getColumnListing('project_user'));
    }

    public function test_revision_and_audit_tables_match_the_milestone_fourteen_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('monthly_reports', 'version'));

        $this->assertEqualsCanonicalizing([
            'id', 'monthly_report_id', 'version', 'snapshot_json', 'generated_pdf_path', 'generated_at', 'finalized_at',
            'finalized_by', 'archived_at', 'archived_by', 'unlock_reason', 'created_at',
        ], Schema::getColumnListing('monthly_report_revisions'));

        $this->assertEqualsCanonicalizing([
            'id', 'monthly_cycle_id', 'monthly_report_id', 'user_id', 'event_type', 'reason', 'metadata_json', 'created_at',
        ], Schema::getColumnListing('monthly_cycle_audit_events'));
    }

    public function test_notes_and_report_tables_match_the_milestone_twelve_columns(): void
    {
        $this->assertEqualsCanonicalizing([
            'id', 'monthly_cycle_id', 'type', 'title', 'body', 'sort_order', 'created_by', 'created_at', 'updated_at',
        ], Schema::getColumnListing('monthly_notes'));

        $this->assertEqualsCanonicalizing([
            'id', 'project_id', 'section_key', 'title', 'is_enabled', 'is_required', 'sort_order', 'created_at', 'updated_at',
        ], Schema::getColumnListing('project_report_sections'));

        $this->assertEqualsCanonicalizing([
            'id', 'monthly_cycle_id', 'status', 'version', 'executive_summary', 'review_notes', 'snapshot_json', 'generated_pdf_path',
            'generated_at', 'finalized_at', 'finalized_by', 'created_at', 'updated_at',
        ], Schema::getColumnListing('monthly_reports'));

        $this->assertEqualsCanonicalizing([
            'id', 'monthly_report_id', 'section_key', 'title', 'is_enabled', 'is_required', 'status', 'sort_order',
            'custom_text', 'settings_json', 'created_at', 'updated_at',
        ], Schema::getColumnListing('monthly_report_sections'));
    }

    public function test_analytics_tables_match_the_milestone_eleven_columns(): void
    {
        $this->assertEqualsCanonicalizing([
            'id', 'monthly_cycle_id', 'clicks', 'impressions', 'ctr', 'average_position', 'source', 'synced_at',
            'entered_by', 'created_at', 'updated_at',
        ], Schema::getColumnListing('gsc_monthly_metrics'));

        $this->assertEqualsCanonicalizing([
            'id', 'monthly_cycle_id', 'query', 'clicks', 'impressions', 'ctr', 'average_position', 'created_at', 'updated_at',
        ], Schema::getColumnListing('gsc_query_metrics'));

        $this->assertEqualsCanonicalizing([
            'id', 'monthly_cycle_id', 'page_id', 'page_url', 'clicks', 'impressions', 'ctr', 'average_position',
            'created_at', 'updated_at',
        ], Schema::getColumnListing('gsc_page_metrics'));

        $this->assertEqualsCanonicalizing([
            'id', 'monthly_cycle_id', 'active_users', 'new_users', 'sessions', 'organic_sessions', 'engaged_sessions',
            'engagement_rate', 'average_engagement_time_seconds', 'event_count', 'key_events', 'source', 'synced_at',
            'entered_by', 'created_at', 'updated_at',
        ], Schema::getColumnListing('ga4_monthly_metrics'));

        $this->assertEqualsCanonicalizing([
            'id', 'monthly_cycle_id', 'country', 'active_users', 'new_users', 'sessions', 'engaged_sessions',
            'engagement_rate', 'event_count', 'key_events', 'created_at', 'updated_at',
        ], Schema::getColumnListing('ga4_country_metrics'));

        $this->assertEqualsCanonicalizing([
            'id', 'monthly_cycle_id', 'moz_domain_authority', 'moz_linking_root_domains', 'ahrefs_domain_rating',
            'ahrefs_url_rating', 'backlinks_count', 'referring_domains_count', 'source', 'synced_at', 'entered_by',
            'notes', 'created_at', 'updated_at',
        ], Schema::getColumnListing('authority_metrics'));
    }

    public function test_content_items_table_matches_the_milestone_ten_columns(): void
    {
        $this->assertEqualsCanonicalizing([
            'id', 'project_id', 'monthly_cycle_id', 'assigned_user_id', 'target_keyword_id', 'title', 'content_type',
            'status', 'planned_publish_date', 'published_at', 'published_url', 'notes',
            'created_at', 'updated_at', 'deleted_at',
        ], Schema::getColumnListing('content_items'));
    }

    public function test_backlinks_table_matches_the_milestone_nine_columns(): void
    {
        $this->assertEqualsCanonicalizing([
            'id', 'project_id', 'monthly_cycle_id', 'created_by', 'published_date', 'published_url', 'anchor_text',
            'target_url', 'type', 'status', 'domain_authority', 'domain_rating', 'spam_score', 'notes',
            'created_at', 'updated_at', 'deleted_at',
        ], Schema::getColumnListing('backlinks'));
    }

    public function test_keyword_tables_match_the_milestone_eight_columns(): void
    {
        $this->assertEqualsCanonicalizing([
            'id', 'project_id', 'keyword', 'keyword_normalized', 'target_page_id', 'keyword_role',
            'search_volume', 'keyword_difficulty', 'search_intent', 'location', 'location_normalized',
            'is_branded', 'status', 'created_at', 'updated_at', 'deleted_at',
        ], Schema::getColumnListing('keywords'));

        // No movement / previous_position columns: movement is derived.
        $this->assertEqualsCanonicalizing([
            'id', 'keyword_id', 'monthly_cycle_id', 'checked_at', 'position', 'ranking_url', 'source', 'created_at',
        ], Schema::getColumnListing('ranking_snapshots'));
    }

    public function test_page_tables_match_the_milestone_seven_columns(): void
    {
        $this->assertEqualsCanonicalizing([
            'id', 'project_id', 'url', 'path', 'title', 'page_type', 'status',
            'created_at', 'updated_at', 'deleted_at',
        ], Schema::getColumnListing('pages'));

        $this->assertEqualsCanonicalizing([
            'id', 'project_id', 'page_id', 'monthly_cycle_id', 'user_id', 'optimized_at',
            'meta_title_updated', 'meta_description_updated', 'content_updated', 'internal_links_updated',
            'schema_updated', 'notes', 'created_at', 'updated_at',
        ], Schema::getColumnListing('page_optimizations'));
    }

    public function test_task_tables_match_the_milestone_six_columns(): void
    {
        $this->assertEqualsCanonicalizing([
            'id', 'name', 'description', 'is_active', 'created_at', 'updated_at',
        ], Schema::getColumnListing('task_templates'));

        $this->assertEqualsCanonicalizing([
            'id', 'task_template_id', 'title', 'description', 'category', 'phase', 'default_due_days',
            'sort_order', 'created_at', 'updated_at',
        ], Schema::getColumnListing('task_template_items'));

        $this->assertEqualsCanonicalizing([
            'id', 'project_id', 'monthly_cycle_id', 'task_template_item_id', 'assigned_user_id', 'created_by',
            'title', 'description', 'category', 'status', 'priority', 'due_date', 'completed_at',
            'created_at', 'updated_at', 'deleted_at',
        ], Schema::getColumnListing('tasks'));
    }

    public function test_monthly_cycle_tables_match_the_milestone_five_columns(): void
    {
        $this->assertEqualsCanonicalizing([
            'id', 'project_id', 'year', 'month', 'status', 'started_at', 'locked_at', 'locked_by',
            'created_at', 'updated_at',
        ], Schema::getColumnListing('monthly_cycles'));

        $this->assertEqualsCanonicalizing([
            'id', 'monthly_cycle_id', 'target_key', 'label', 'target_value', 'created_at', 'updated_at',
        ], Schema::getColumnListing('monthly_cycle_targets'));
    }

    public function test_package_tables_match_the_milestone_four_columns(): void
    {
        $this->assertEqualsCanonicalizing([
            'id', 'name', 'description', 'is_active', 'created_at', 'updated_at',
        ], Schema::getColumnListing('packages'));

        $this->assertEqualsCanonicalizing([
            'id', 'package_id', 'target_key', 'label', 'target_value', 'sort_order', 'created_at', 'updated_at',
        ], Schema::getColumnListing('package_targets'));

        $this->assertEqualsCanonicalizing([
            'id', 'project_id', 'target_key', 'label', 'target_value', 'created_at', 'updated_at',
        ], Schema::getColumnListing('project_target_overrides'));
    }
}
