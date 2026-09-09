<?php

namespace Tests\Support;

use App\Enums\UserRole;
use App\Models\LegacyMigrationRun;
use App\Models\Package;
use App\Models\User;
use App\Services\LegacyMigration\LegacyMapping;
use App\Services\LegacyMigration\LegacyMigrator;
use App\Support\LegacyMigration\MigrationReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Drives the legacy migration against the anonymised fixture workbook
 * (tests/Fixtures/legacy/sample-agency, one CSV per sheet) and can render
 * the same sheets as a real .xlsx workbook to exercise the XLSX reader.
 */
trait RunsLegacyMigrations
{
    protected User $admin;

    protected User $manager;

    protected User $executive;

    protected Package $growth;

    protected function setUpLegacyFixtureUsers(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->admin = User::factory()->superAdmin()->create(['email' => 'admin@sample.test', 'name' => 'Ava Admin']);
        $this->manager = User::factory()->seoManager()->create(['email' => 'manager@sample.test', 'name' => 'Morgan Manager']);
        $this->executive = User::factory()->seoExecutive()->create(['email' => 'exec@sample.test', 'name' => 'Eli Executive']);

        // Today's package: deliberately DIFFERENT from the legacy July targets.
        $this->growth = Package::factory()->withTargets([
            ['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 10],
            ['target_key' => 'guest_posts', 'label' => 'Guest posts', 'target_value' => 3],
            ['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 6],
            ['target_key' => 'pages_optimized', 'label' => 'Pages optimised', 'target_value' => 5],
        ])->create(['name' => 'Growth']);
    }

    protected function fixtureDirectory(): string
    {
        return base_path('tests/Fixtures/legacy/sample-agency');
    }

    /**
     * @return array<string, list<list<string>>> sheet name => rows (header first)
     */
    protected function fixtureSheets(): array
    {
        $sheets = [];

        foreach (glob($this->fixtureDirectory().'/*.csv') as $file) {
            $handle = fopen($file, 'rb');
            $rows = [];

            while (($cells = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                $rows[] = array_map(fn ($c): string => (string) $c, $cells);
            }

            fclose($handle);
            $sheets[pathinfo($file, PATHINFO_FILENAME)] = $rows;
        }

        return $sheets;
    }

    /**
     * @param  array<string, list<list<mixed>>>  $sheets
     */
    protected function writeXlsx(array $sheets, ?string $path = null): string
    {
        $path ??= tempnam(sys_get_temp_dir(), 'legacy-').'.xlsx';
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $first = true;

        foreach ($sheets as $name => $rows) {
            $sheet = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName($name);
            $first = false;

            foreach ($rows as $row) {
                // Date cells carry a date number format, as a Google Sheets / Excel export does.
                $writer->addRow(new Row(array_map(fn ($value): Cell => $value instanceof \DateTimeInterface
                    ? Cell::fromValue($value, (new Style)->setFormat('yyyy-mm-dd hh:mm:ss'))
                    : Cell::fromValue($value), $row)));
            }
        }

        $writer->close();

        return $path;
    }

    protected function migrate(bool $dryRun, ?string $source = null, array $mappingOverride = [], ?User $actor = null, ?string $sourceId = null): LegacyMigrationRun
    {
        config(['legacy-migration' => array_replace_recursive((array) config('legacy-migration'), $mappingOverride)]);

        return app(LegacyMigrator::class)->run($source ?? $this->fixtureDirectory(), $actor ?? $this->admin, $dryRun, LegacyMapping::fromConfig(), $sourceId);
    }

    protected function reportOf(LegacyMigrationRun $run): array
    {
        return (array) data_get($run->metadata_json, 'report', []);
    }

    protected function tallyOf(LegacyMigrationRun $run, string $entity, string $outcome): int
    {
        return (int) data_get($this->reportOf($run), "entities.{$entity}.{$outcome}", 0);
    }

    /**
     * @return list<string>
     */
    protected function messages(LegacyMigrationRun $run, ?string $severity = null): array
    {
        return $run->issues()->when($severity, fn ($q) => $q->where('severity', $severity))->pluck('message')->all();
    }

    protected function assertHasIssue(LegacyMigrationRun $run, string $needle, ?string $severity = null): void
    {
        $messages = $this->messages($run, $severity);

        $this->assertTrue(
            collect($messages)->contains(fn (string $m): bool => str_contains($m, $needle)),
            "No {$severity} issue containing [{$needle}]. Issues:\n".implode("\n", $messages),
        );
    }

    protected function domainSnapshot(): array
    {
        $tables = ['clients', 'projects', 'project_user', 'monthly_cycles', 'monthly_cycle_targets', 'pages', 'page_optimizations', 'keywords', 'ranking_snapshots', 'backlinks', 'content_items', 'tasks', 'gsc_monthly_metrics', 'gsc_query_metrics', 'gsc_page_metrics', 'ga4_monthly_metrics', 'ga4_country_metrics', 'authority_metrics', 'monthly_notes', 'monthly_reports', 'legacy_migration_records'];
        $snapshot = [];

        foreach ($tables as $table) {
            $snapshot[$table] = (int) DB::table($table)->count();
        }

        return $snapshot;
    }

    protected function superAdminRole(): UserRole
    {
        return UserRole::SuperAdmin;
    }

    protected function reportEntities(): array
    {
        return MigrationReport::ENTITIES;
    }
}
