<?php

namespace Tests\Feature\Production;

use App\Exceptions\CsvImportException;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\ReportsOverview;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Imports\Pages\ListImportBatches;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\Pages\ProjectBacklinks;
use App\Filament\Resources\Projects\Pages\ProjectContent;
use App\Filament\Resources\Projects\Pages\ProjectKeywords;
use App\Filament\Resources\Projects\Pages\ProjectPages;
use App\Filament\Resources\Projects\Pages\ProjectTasks;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Project;
use App\Models\User;
use App\Services\Imports\CsvReader;
use App\Services\Reports\PdfReportGenerator;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ProductionConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_and_import_configuration_are_read_through_config_so_caching_is_safe(): void
    {
        Storage::fake('archive');
        config(['reports.pdf_disk' => 'archive', 'reports.pdf_directory' => 'final-pdfs', 'reports.chromium_timeout' => 45, 'reports.chromium_path' => 'C:/x/chrome.exe']);

        $generator = app(PdfReportGenerator::class);
        $this->assertSame(Storage::disk('archive'), $generator->disk());
        $this->assertSame(45, config('reports.chromium_timeout'));

        config(['imports.max_rows' => 2, 'imports.max_file_bytes' => 100]);
        $reader = app(CsvReader::class);

        try {
            $reader->inspectUpload(UploadedFile::fake()->createWithContent('k.csv', "keyword\na\nb\nc\n"));
            $this->fail('the cached row limit must apply');
        } catch (CsvImportException $exception) {
            $this->assertStringContainsString('more than 2 data rows', $exception->getMessage());
        }

        $this->expectExceptionMessage('larger than');
        $reader->inspectUpload(UploadedFile::fake()->createWithContent('k.csv', str_repeat("keyword,volume\n", 20)));
    }

    public function test_no_runtime_env_usage_exists_outside_the_config_directory(): void
    {
        $offenders = [];

        foreach ([app_path(), base_path('routes'), base_path('bootstrap/app.php'), resource_path('views'), database_path('migrations'), database_path('seeders')] as $path) {
            $files = is_dir($path) ? File::allFiles($path) : [new \SplFileInfo($path)];

            foreach ($files as $file) {
                if (preg_match('/\benv\s*\(/', File::get($file->getPathname())) === 1) {
                    $offenders[] = $file->getPathname();
                }
            }
        }

        $this->assertSame([], $offenders, 'env() outside config/ breaks `php artisan config:cache`');
    }

    public function test_the_scheduler_registers_exactly_the_intended_commands(): void
    {
        $events = collect(app(Schedule::class)->events());
        $commands = $events->map(fn ($e): string => (string) $e->command)->all();

        $this->assertCount(2, $events);
        $this->assertTrue($events->every(fn ($e): bool => $e->withoutOverlapping), 'every scheduled command uses withoutOverlapping');

        foreach (['seo:ensure-monthly-cycles', 'seo:prune-import-files'] as $name) {
            $this->assertTrue(collect($commands)->contains(fn (string $c): bool => str_contains($c, $name)), $name);
        }

        foreach (['sync', 'gsc', 'ga4', 'ahrefs', 'semrush', 'report:', 'migrate-legacy'] as $forbidden) {
            $this->assertFalse(collect($commands)->contains(fn (string $c): bool => str_contains($c, $forbidden)), "no scheduled {$forbidden}");
        }
    }

    public function test_large_operational_tables_stay_paginated(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $project = Project::factory()->create();

        foreach ([
            [ListClients::class, []], [ListProjects::class, []], [ListUsers::class, []], [ListImportBatches::class, []],
            [ReportsOverview::class, []], [Dashboard::class, []],
            [ProjectTasks::class, ['record' => $project->getKey()]], [ProjectKeywords::class, ['record' => $project->getKey()]],
            [ProjectBacklinks::class, ['record' => $project->getKey()]], [ProjectContent::class, ['record' => $project->getKey()]],
            [ProjectPages::class, ['record' => $project->getKey()]],
        ] as [$page, $params]) {
            $table = Livewire::test($page, $params)->instance()->getTable();
            $this->assertTrue($table->isPaginated(), "{$page} must paginate");
        }
    }

    public function test_no_client_portal_api_or_sync_surface_exists(): void
    {
        $this->assertFalse(File::exists(base_path('routes/api.php')));

        $names = array_keys(app('router')->getRoutes()->getRoutesByName());
        $this->assertEmpty(array_filter($names, fn (string $n): bool => str_starts_with($n, 'api.') || str_contains($n, 'portal') || str_contains($n, 'sync') || str_contains($n, 'oauth')));

        foreach (app('router')->getRoutes() as $route) {
            $this->assertStringNotContainsString('/api/', '/'.$route->uri().'/');
        }

        $composer = json_decode(File::get(base_path('composer.json')), true);
        $this->assertEqualsCanonicalizing(['php', 'filament/filament', 'laravel/framework', 'laravel/tinker', 'openspout/openspout'], array_keys($composer['require']));
    }
}
