<?php

namespace App\Services\LegacyMigration;

use App\Enums\LegacyMigrationStatus;
use App\Enums\UserRole;
use App\Models\LegacyMigrationIssue;
use App\Models\LegacyMigrationRun;
use App\Models\User;
use App\Services\Imports\CsvValueNormalizer;
use App\Services\LegacyMigration\Mappers\LegacyAnalyticsDetailMapper;
use App\Services\LegacyMigration\Mappers\LegacyAnalyticsMapper;
use App\Services\LegacyMigration\Mappers\LegacyBacklinkMapper;
use App\Services\LegacyMigration\Mappers\LegacyClientProjectMapper;
use App\Services\LegacyMigration\Mappers\LegacyContentMapper;
use App\Services\LegacyMigration\Mappers\LegacyKeywordRankingMapper;
use App\Services\LegacyMigration\Mappers\LegacyNoteMapper;
use App\Services\LegacyMigration\Mappers\LegacyPageOptimizationMapper;
use App\Services\LegacyMigration\Mappers\LegacySheetMapper;
use App\Services\LegacyMigration\Mappers\LegacyTargetMapper;
use App\Services\LegacyMigration\Mappers\LegacyTaskMapper;
use App\Support\LegacyMigration\LegacySheet;
use App\Support\LegacyMigration\LegacyWorkbook;
use App\Support\LegacyMigration\MigrationContext;
use App\Support\LegacyMigration\MigrationIssue;
use App\Support\LegacyMigration\MigrationReport;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Runs one legacy workbook through the explicit sheet mappers, in a fixed
 * order (projects before everything that references them, targets before
 * cycles are created), one database transaction per (sheet, group) so a
 * failing project/month is rolled back and reported without touching the
 * others.
 *
 * Dry run: the whole run executes inside an outer transaction that is
 * rolled back at the end, so the report reflects exactly what apply would
 * do while the domain stays untouched. The run manifest and its issues
 * are written outside that transaction.
 */
class LegacyMigrator
{
    public function __construct(
        protected LegacyWorkbookReader $reader,
    ) {}

    /**
     * @return list<LegacySheetMapper>
     */
    public function mappers(): array
    {
        return [
            app(LegacyClientProjectMapper::class),
            app(LegacyTargetMapper::class),
            app(LegacyKeywordRankingMapper::class),
            app(LegacyBacklinkMapper::class),
            app(LegacyContentMapper::class),
            app(LegacyTaskMapper::class),
            app(LegacyPageOptimizationMapper::class),
            app(LegacyAnalyticsMapper::class),
            app(LegacyAnalyticsDetailMapper::class, ['kind' => LegacyAnalyticsDetailMapper::GSC_QUERIES]),
            app(LegacyAnalyticsDetailMapper::class, ['kind' => LegacyAnalyticsDetailMapper::GSC_PAGES]),
            app(LegacyAnalyticsDetailMapper::class, ['kind' => LegacyAnalyticsDetailMapper::GA4_COUNTRIES]),
            app(LegacyNoteMapper::class),
        ];
    }

    public function run(string $sourcePath, User $actor, bool $dryRun, LegacyMapping $mapping, ?string $sourceIdentifier = null): LegacyMigrationRun
    {
        if (! $actor->hasRole(UserRole::SuperAdmin) || ! $actor->is_active) {
            throw new InvalidArgumentException('Legacy migration must be run by an active Super Admin (--actor).');
        }

        $workbook = $this->reader->read($sourcePath);
        $sourceIdentifier ??= $workbook->filename;

        $run = LegacyMigrationRun::query()->create([
            'source_identifier' => $sourceIdentifier,
            'source_filename' => $workbook->filename,
            'source_checksum' => $workbook->checksum,
            'mapper_version' => LegacyMapping::VERSION,
            'mode' => $dryRun ? LegacyMigrationRun::MODE_DRY_RUN : LegacyMigrationRun::MODE_APPLY,
            'status' => $dryRun ? LegacyMigrationStatus::DryRun : LegacyMigrationStatus::Running,
            'created_by' => $actor->getKey(),
            'started_at' => now(),
            'metadata_json' => ['sheets' => $workbook->sheetNames(), 'mapping_file' => $mapping->mappingFile],
        ]);

        $report = new MigrationReport($workbook->filename, $workbook->checksum, LegacyMapping::VERSION, $run->mode);
        $this->notePreviousRuns($run, $report);

        $context = new MigrationContext(
            run: $run,
            workbook: $workbook,
            actor: $actor,
            dryRun: $dryRun,
            mapping: $mapping,
            values: new LegacyValueMapper($mapping, app(CsvValueNormalizer::class)),
            report: $report,
            ledger: new MigrationLedger($run),
            projects: new ProjectDirectory($mapping),
            cycles: app(HistoricalCycleResolver::class),
        );

        if ($dryRun) {
            DB::beginTransaction();
        }

        try {
            $this->migrate($context, $workbook);
        } finally {
            if ($dryRun) {
                DB::rollBack();
            }
        }

        $this->finish($run, $report, $dryRun ? LegacyMigrationStatus::DryRun : LegacyMigrationStatus::Completed);

        return $run->refresh();
    }

    protected function migrate(MigrationContext $context, LegacyWorkbook $workbook): void
    {
        $known = [];

        foreach ($this->mappers() as $mapper) {
            $known[] = LegacySheet::normaliseName($mapper->sheet());
            $sheet = $workbook->sheet($mapper->sheet());

            if ($sheet === null) {
                continue;
            }

            $resolved = $this->resolveColumns($mapper, $sheet, $context);

            if ($resolved === null) {
                continue;
            }

            foreach ($this->groups($resolved, $sheet) as $group => $rows) {
                $this->migrateGroup($context, $resolved, $sheet, (string) $group, $rows);
            }
        }

        foreach ($workbook->sheets as $sheet) {
            if (! in_array($sheet->normalisedName(), $known, true)) {
                $context->warning($sheet->name, null, null, sprintf('Sheet "%s" is not a known legacy sheet (%s); ignored.', $sheet->name, implode(', ', array_map(fn (LegacySheetMapper $m): string => $m->sheet(), $this->mappers()))));
            }
        }
    }

    protected function resolveColumns(LegacySheetMapper $mapper, LegacySheet $sheet, MigrationContext $context): ?LegacySheetMapper
    {
        $columns = [];
        $used = [];

        foreach ($mapper->fields() as $field => $definition) {
            $header = $sheet->header([$field, str_replace('_', ' ', $field), ...($definition['aliases'] ?? [])]);

            if ($header !== null && ! isset($used[$header])) {
                $columns[$field] = $header;
                $used[$header] = true;
            } elseif ($definition['required'] ?? false) {
                $context->error($sheet->name, null, null, sprintf('Sheet "%s" has no "%s" column; the sheet was skipped.', $sheet->name, str_replace('_', ' ', $field)));

                return null;
            }
        }

        foreach ($sheet->headers as $header) {
            if (isset($used[$header])) {
                continue;
            }

            try {
                if ($mapper->acceptsDynamicColumn($header, $context)) {
                    continue;
                }
            } catch (InvalidArgumentException $exception) {
                $context->error($sheet->name, null, null, $exception->getMessage());

                return null;
            }

            $context->report->unmappedColumn($sheet->name, $header);
        }

        return $mapper->withColumns($columns);
    }

    /**
     * @return array<string, array<int, array<string, string>>>
     */
    protected function groups(LegacySheetMapper $mapper, LegacySheet $sheet): array
    {
        $groups = [];

        foreach ($sheet->rows as $rowNumber => $row) {
            $groups[$mapper->value($row, $mapper->groupField()) ?: '(blank)'][$rowNumber] = $row;
        }

        return $groups;
    }

    protected function migrateGroup(MigrationContext $context, LegacySheetMapper $mapper, LegacySheet $sheet, string $group, array $rows): void
    {
        try {
            DB::transaction(fn () => $mapper->migrateGroup($context, $sheet, $group, $rows));

            $context->commitPending();
            $context->cycles->groupCommitted();
        } catch (Throwable $exception) {
            $context->discardPending();
            $context->cycles->groupRolledBack();
            report($exception);

            $context->error($sheet->name, array_key_first($rows), null, sprintf('Sheet "%s", group "%s" (%d row(s)) failed and was rolled back entirely: %s', $sheet->name, $group, count($rows), $exception->getMessage()));
        }
    }

    protected function notePreviousRuns(LegacyMigrationRun $run, MigrationReport $report): void
    {
        $previous = LegacyMigrationRun::query()
            ->whereKeyNot($run->getKey())
            ->where('source_checksum', $run->source_checksum)
            ->where('mode', LegacyMigrationRun::MODE_APPLY)
            ->orderByDesc('id')
            ->first();

        if ($previous !== null) {
            $report->issue(MigrationIssue::info('(workbook)', null, null, sprintf('This exact source (checksum %s) was already applied by run #%d on %s; rerun is idempotent.', substr($run->source_checksum, 0, 12), $previous->getKey(), $previous->completed_at?->format('Y-m-d H:i') ?? 'unknown date')));
        }
    }

    protected function finish(LegacyMigrationRun $run, MigrationReport $report, LegacyMigrationStatus $status): void
    {
        DB::transaction(function () use ($run, $report, $status): void {
            $now = now();

            foreach (array_chunk($report->issues(), 500) as $chunk) {
                LegacyMigrationIssue::query()->insert(array_map(fn (MigrationIssue $issue): array => [
                    'legacy_migration_run_id' => $run->getKey(),
                    'source_sheet' => mb_substr($issue->sheet, 0, 100),
                    'source_row' => $issue->row,
                    'severity' => $issue->severity,
                    'entity_type' => $issue->entity,
                    'message' => mb_substr($issue->message, 0, 1000),
                    'raw_data_json' => $issue->raw === null ? null : json_encode($issue->raw, JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                ], $chunk));
            }

            $run->forceFill([
                'status' => $status,
                'total_items' => $report->totalItems(),
                'created_items' => $report->total('create'),
                'updated_items' => $report->total('update'),
                'skipped_items' => $report->total('skip'),
                'conflict_items' => $report->total('conflict'),
                'warning_count' => $report->warnings(),
                'error_count' => $report->errors(),
                'metadata_json' => array_merge((array) $run->metadata_json, ['report' => $report->toArray()]),
                'completed_at' => $now,
            ])->save();
        });
    }
}
