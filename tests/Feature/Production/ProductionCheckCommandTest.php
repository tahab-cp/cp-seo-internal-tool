<?php

namespace Tests\Feature\Production;

use App\Console\Commands\ProductionCheckCommand;
use App\Models\Client;
use App\Models\ImportBatch;
use App\Models\LegacyMigrationRun;
use App\Models\Project;
use App\Models\User;
use App\Services\Reports\PdfReportGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductionCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function preflight(array $options = []): array
    {
        // The suite runs with the sync queue; production uses the database queue, so check that path.
        config(['queue.default' => 'database']);

        $code = Artisan::call('app:production-check', $options + ['--skip-chromium' => true]);

        return [$code, Artisan::output()];
    }

    protected function row(string $output, string $check): string
    {
        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^\|\s*(PASS|WARNING|FAIL)\s*\|\s*'.preg_quote($check, '/').'\s*\|/', $line) === 1) {
                return $line;
            }
        }

        $this->fail("No result row for [{$check}] in:\n{$output}");
    }

    protected function assertStatus(string $status, string $output, string $check): void
    {
        $this->assertStringContainsString("| {$status}", $this->row($output, $check), "expected {$status} for {$check}");
    }

    public function test_preflight_passes_on_the_test_environment_and_never_prints_secrets(): void
    {
        $secretKey = 'base64:'.base64_encode(str_repeat('K', 32));
        config(['app.key' => $secretKey, 'database.connections.'.config('database.default').'.password' => 'Sup3rSecretPassw0rd']);
        User::factory()->superAdmin()->create();

        [$code, $output] = $this->preflight();

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('All critical checks passed', $output);
        $this->assertStringNotContainsString('Sup3rSecretPassw0rd', $output);
        $this->assertStringNotContainsString(base64_encode(str_repeat('K', 32)), $output);
        $this->assertStringNotContainsString(config('database.connections.'.config('database.default').'.username'), $output.'x', 'the database user is not printed either');

        foreach (['APP_KEY', 'Database connection', 'Migrations', 'Required tables', 'Report PDF disk', 'Import disk', 'Queue tables', 'Scheduled commands', 'Active Super Admin', 'Report PDFs private'] as $check) {
            $this->assertStatus('PASS', $output, $check);
        }

        $this->assertStringContainsString('cp_seo_internal_tool_testing', $this->row($output, 'Database connection'));
        $this->assertStatus('WARNING', $output, 'APP_ENV');
    }

    public function test_a_missing_app_key_fails_the_preflight(): void
    {
        config(['app.key' => '']);

        [$code, $output] = $this->preflight();

        $this->assertSame(1, $code);
        $this->assertStatus('FAIL', $output, 'APP_KEY');
        $this->assertStringContainsString('key:generate ONCE on a new install (never on an existing one)', $output);
        $this->assertStringContainsString('check(s) FAILED', $output);
    }

    public function test_debug_mode_is_a_warning_normally_and_a_failure_when_production_is_expected(): void
    {
        config(['app.debug' => true]);

        [$code, $output] = $this->preflight();
        $this->assertSame(0, $code);
        $this->assertStatus('WARNING', $output, 'APP_DEBUG');

        [$code, $output] = $this->preflight(['--production' => true]);
        $this->assertSame(1, $code);
        $this->assertStatus('FAIL', $output, 'APP_DEBUG');
        $this->assertStatus('FAIL', $output, 'APP_ENV');
        $this->assertStatus('FAIL', $output, 'APP_URL');
        $this->assertStringContainsString('stack traces, SQL and paths would be shown', $output);
    }

    public function test_database_connectivity_is_checked_safely(): void
    {
        $default = config('database.default');
        config([
            'database.default' => 'preflight-broken',
            'database.connections.preflight-broken' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'nowhere', 'username' => 'nobody', 'password' => 'NotAReal-Passw0rd', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true],
        ]);

        try {
            [$code, $output] = $this->preflight();
        } finally {
            config(['database.default' => $default]);
        }

        $this->assertSame(1, $code);
        $this->assertStatus('FAIL', $output, 'Database connection');
        $this->assertStringContainsString('nowhere@127.0.0.1', $output);
        $this->assertStringNotContainsString('NotAReal-Passw0rd', $output);
        $this->assertStringNotContainsString('nobody', $output);
    }

    public function test_required_tables_and_pending_migrations_are_verified(): void
    {
        $this->assertContains('monthly_report_revisions', ProductionCheckCommand::REQUIRED_TABLES);
        $this->assertContains('legacy_migration_records', ProductionCheckCommand::REQUIRED_TABLES);
        $this->assertTrue(Schema::hasTable('keywords'));

        // A table the schema does not have (as after a forgotten migration) fails the check.
        config(['security.preflight_required_tables' => [...ProductionCheckCommand::REQUIRED_TABLES, 'report_files', 'client_portal_users']]);

        [$code, $output] = $this->preflight();

        $this->assertSame(1, $code);
        $this->assertStatus('FAIL', $output, 'Required tables');
        $this->assertStringContainsString('missing: report_files, client_portal_users', $output);
        $this->assertStatus('PASS', $output, 'Migrations');
        $this->assertStringContainsString('applied, none pending', $output);
    }

    public function test_report_and_import_disks_are_verified_without_leaving_files_behind(): void
    {
        Storage::fake('pdfs');
        Storage::fake('uploads');
        config(['reports.pdf_disk' => 'pdfs', 'imports.disk' => 'uploads']);

        [$code, $output] = $this->preflight();

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('[pdfs] local driver, reports/ read/write ok', $this->row($output, 'Report PDF disk'));
        $this->assertStringContainsString('[uploads] local driver, imports/ read/write ok', $this->row($output, 'Import disk'));
        $this->assertSame([], Storage::disk('pdfs')->allFiles(), 'probe files are removed');
        $this->assertSame([], Storage::disk('uploads')->allFiles());

        config(['reports.pdf_disk' => 'does-not-exist']);
        [$code, $output] = $this->preflight();
        $this->assertSame(1, $code);
        $this->assertStatus('FAIL', $output, 'Report PDF disk');
        $this->assertStringContainsString('disk [does-not-exist] is not configured', $output);

        Storage::fake('public');
        config(['reports.pdf_disk' => 'public']);
        [$code, $output] = $this->preflight();
        $this->assertSame(1, $code);
        $this->assertStatus('FAIL', $output, 'Report PDFs private');
        $this->assertStringContainsString('final reports would be reachable without login', $output);
    }

    public function test_chromium_absence_is_reported_clearly(): void
    {
        config(['reports.chromium_path' => 'C:/definitely/not/here/chrome.exe']);

        [$code, $output] = $this->preflight();

        $this->assertSame(1, $code);
        $this->assertStatus('FAIL', $output, 'Chromium');
        $this->assertStringContainsString('CHROMIUM_PATH [C:/definitely/not/here/chrome.exe] does not exist', $output);

        config(['reports.chromium_path' => null, 'reports.chromium_candidates' => []]);
        [$code, $output] = $this->preflight();
        $this->assertSame(1, $code);
        $this->assertStringContainsString('No Chromium-based browser found. Set CHROMIUM_PATH', $output);
    }

    public function test_chromium_success_is_reported_with_its_version_when_a_browser_is_installed(): void
    {
        try {
            $binary = app(PdfReportGenerator::class)->chromiumBinary();
        } catch (\Throwable) {
            $this->markTestSkipped('No Chromium-based browser on this machine.');
        }

        $code = Artisan::call('app:production-check');
        $output = Artisan::output();

        $this->assertSame(0, $code, $output);
        $row = $this->row($output, 'Chromium');
        $this->assertStringContainsString('PASS', $row);
        $this->assertStringContainsString(basename($binary), $row);
        $this->assertMatchesRegularExpression('/\d+\.\d+/', $row, 'a version number is printed');
        $this->assertStatus('PASS', $output, 'Chromium sandbox');

        config(['reports.chromium_flags' => ['--no-sandbox']]);
        Artisan::call('app:production-check', ['--skip-chromium' => true]);
        $this->assertStatus('WARNING', Artisan::output(), 'Chromium sandbox');
    }

    public function test_preflight_never_mutates_domain_data(): void
    {
        $client = Client::factory()->create();
        Project::factory()->forClient($client)->create();
        $before = collect(['clients', 'projects', 'monthly_cycles', 'keywords', 'ranking_snapshots', 'monthly_reports', 'import_batches', 'legacy_migration_runs', 'users', 'jobs'])
            ->mapWithKeys(fn (string $t) => [$t => DB::table($t)->count()])->all();

        $this->preflight();
        $this->preflight(['--production' => true]);

        $after = collect(array_keys($before))->mapWithKeys(fn (string $t) => [$t => DB::table($t)->count()])->all();
        $this->assertSame($before, $after);
        $this->assertSame(0, ImportBatch::query()->count());
        $this->assertSame(0, LegacyMigrationRun::query()->count());
    }
}
