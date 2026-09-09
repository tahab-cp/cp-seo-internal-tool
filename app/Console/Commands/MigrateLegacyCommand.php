<?php

namespace App\Console\Commands;

use App\Models\LegacyMigrationRun;
use App\Models\User;
use App\Services\LegacyMigration\LegacyMapping;
use App\Services\LegacyMigration\LegacyMigrator;
use App\Support\LegacyMigration\MigrationOutcome;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * Administrative legacy spreadsheet migration (Milestone 17). Not a
 * product feature: no Filament UI, Super Admin actor only, read-only
 * against the source, dry run by explicit flag, apply by explicit flag.
 */
class MigrateLegacyCommand extends Command
{
    protected $signature = 'seo:migrate-legacy
        {source : Path to the legacy .xlsx workbook, or a directory of per-sheet .csv files}
        {--dry-run : Parse, map and validate; roll back every domain change}
        {--apply : Actually migrate (required for any change)}
        {--actor= : Email of the active Super Admin performing the migration}
        {--mapping= : JSON mapping file merged over config/legacy-migration.php}
        {--source-id= : Stable identifier for reruns (defaults to the file or directory name)}
        {--json-report= : Also write the report as JSON to this path}';

    protected $description = 'Migrate the office\'s legacy SEO spreadsheets into the application (dry run or apply)';

    public function handle(LegacyMigrator $migrator): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');

        if ($dryRun === $apply) {
            $this->error('Choose exactly one of --dry-run or --apply. Nothing was done.');

            return self::INVALID;
        }

        $actorEmail = trim((string) $this->option('actor'));

        if ($actorEmail === '') {
            $this->error('--actor=<email> is required: the active Super Admin performing the migration.');

            return self::INVALID;
        }

        $actor = User::query()->whereRaw('LOWER(email) = ?', [strtolower($actorEmail)])->first();

        if ($actor === null) {
            $this->error("No user with email [{$actorEmail}].");

            return self::INVALID;
        }

        try {
            $mapping = LegacyMapping::fromConfig(filled($this->option('mapping')) ? (string) $this->option('mapping') : null);
            $run = $migrator->run((string) $this->argument('source'), $actor, $dryRun, $mapping, filled($this->option('source-id')) ? (string) $this->option('source-id') : null);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('The migration could not be completed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->render($run);

        if (filled($this->option('json-report'))) {
            $path = (string) $this->option('json-report');
            file_put_contents($path, json_encode(array_merge(['run_id' => $run->getKey()], (array) data_get($run->metadata_json, 'report', [])), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $this->line("JSON report written to {$path}");
        }

        return $run->error_count > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function render(LegacyMigrationRun $run): void
    {
        $report = (array) data_get($run->metadata_json, 'report', []);

        $this->newLine();
        $this->info(sprintf('%s run #%d — %s', $run->isDryRun() ? 'DRY RUN (nothing written)' : 'APPLY', $run->getKey(), $run->source_filename));
        $this->line("Checksum: {$run->source_checksum}");
        $this->line("Mapper version: {$run->mapper_version}   Mode: {$run->mode}   Status: {$run->status->value}");
        $this->newLine();

        $rows = [];

        foreach ((array) ($report['entities'] ?? []) as $entity => $counts) {
            if (array_sum($counts) === 0 && ! in_array($entity, ['clients', 'projects', 'cycles'], true)) {
                continue;
            }

            $rows[] = [str_replace('_', ' ', $entity), $counts[MigrationOutcome::CREATE] ?? 0, $counts[MigrationOutcome::UPDATE] ?? 0, $counts[MigrationOutcome::SKIP] ?? 0, $counts[MigrationOutcome::CONFLICT] ?? 0];
        }

        $this->table(['Entity', 'Create', 'Update', 'Skip / reuse', 'Conflict'], $rows);

        $this->line(sprintf('Items: %d   Warnings: %d   Errors: %d', $run->total_items, $run->warning_count, $run->error_count));

        $unmapped = (array) ($report['unmapped_columns'] ?? []);

        if ($unmapped !== []) {
            $this->newLine();
            $this->warn('Unmapped source columns (ignored by the current mapping):');

            foreach ($unmapped as $column) {
                $this->line("  - {$column}");
            }
        }

        $issues = array_filter((array) ($report['issues'] ?? []), fn (array $i): bool => $i['severity'] !== 'info');

        if ($issues !== []) {
            $this->newLine();
            $this->warn('Warnings and errors:');

            foreach (array_slice($issues, 0, 200) as $issue) {
                $this->line(sprintf('  [%s] %s%s: %s', strtoupper($issue['severity']), $issue['sheet'], $issue['row'] !== null ? " row {$issue['row']}" : '', $issue['message']));
            }

            if (count($issues) > 200) {
                $this->line(sprintf('  … and %d more (see legacy_migration_issues for run #%d).', count($issues) - 200, $run->getKey()));
            }
        }

        if (! $run->isDryRun()) {
            $this->newLine();
            $this->info('Apply completed. Groups with errors were rolled back individually; rerun the same source after fixing them.');
        }
    }
}
