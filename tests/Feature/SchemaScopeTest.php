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
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected table [{$table}] to exist.");
        }
    }

    public function test_no_seo_domain_tables_exist_yet(): void
    {
        $future = [
            'monthly_cycles', 'monthly_cycle_targets', 'task_templates', 'task_template_items', 'tasks',
            'pages', 'page_optimizations', 'keywords', 'ranking_snapshots', 'backlinks', 'content_items',
            'gsc_monthly_metrics', 'gsc_query_metrics', 'gsc_page_metrics', 'ga4_monthly_metrics',
            'ga4_country_metrics', 'authority_metrics', 'monthly_notes', 'project_report_sections',
            'monthly_reports', 'monthly_report_sections',
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
