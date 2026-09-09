<?php

namespace App\Support\LegacyMigration;

use App\Models\LegacyMigrationRun;
use App\Models\User;
use App\Services\LegacyMigration\HistoricalCycleResolver;
use App\Services\LegacyMigration\LegacyMapping;
use App\Services\LegacyMigration\LegacyValueMapper;
use App\Services\LegacyMigration\MigrationLedger;
use App\Services\LegacyMigration\ProjectDirectory;

/**
 * Everything the mappers share during one run. Tallies are buffered per
 * transaction group and only merged into the report when the group
 * commits, so a rolled-back group never inflates the totals.
 */
final class MigrationContext
{
    /**
     * @var array<string, array<string, int>>
     */
    private array $pending = [];

    public function __construct(
        public readonly LegacyMigrationRun $run,
        public readonly LegacyWorkbook $workbook,
        public readonly User $actor,
        public readonly bool $dryRun,
        public readonly LegacyMapping $mapping,
        public readonly LegacyValueMapper $values,
        public readonly MigrationReport $report,
        public readonly MigrationLedger $ledger,
        public readonly ProjectDirectory $projects,
        public readonly HistoricalCycleResolver $cycles,
    ) {}

    public function tally(string $entity, string $outcome): void
    {
        $this->pending[$entity][$outcome] = ($this->pending[$entity][$outcome] ?? 0) + 1;
    }

    public function commitPending(): void
    {
        foreach ($this->pending as $entity => $outcomes) {
            foreach ($outcomes as $outcome => $times) {
                $this->report->tally($entity, $outcome, $times);
            }
        }

        $this->pending = [];
    }

    public function discardPending(): void
    {
        $this->pending = [];
    }

    public function issue(MigrationIssue $issue): void
    {
        $this->report->issue($issue);
    }

    public function info(string $sheet, ?int $row, ?string $entity, string $message): void
    {
        $this->issue(MigrationIssue::info($sheet, $row, $entity, $message));
    }

    public function warning(string $sheet, ?int $row, ?string $entity, string $message, ?array $raw = null): void
    {
        $this->issue(MigrationIssue::warning($sheet, $row, $entity, $message, $raw));
    }

    public function error(string $sheet, ?int $row, ?string $entity, string $message, ?array $raw = null): void
    {
        $this->issue(MigrationIssue::error($sheet, $row, $entity, $message, $raw));
    }
}
