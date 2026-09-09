<?php

namespace App\Services\LegacyMigration\Mappers;

use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Support\LegacyMigration\LegacySheet;
use App\Support\LegacyMigration\MigrationContext;
use App\Support\LegacyMigration\MigrationOutcome;
use App\Support\MonthlyCycles\CyclePeriod;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * One explicit legacy sheet format. A mapper declares the sheet it reads
 * and the columns it understands (with small aliases); the migrator
 * resolves columns, reports unmapped ones, splits rows into transaction
 * groups and calls migrateGroup(). Mappers translate cells into the
 * payloads the EXISTING domain actions expect; they never write SQL.
 */
abstract class LegacySheetMapper
{
    /**
     * @var array<string, string> field => resolved header (set by the migrator)
     */
    protected array $columns = [];

    abstract public function sheet(): string;

    /**
     * @return array<string, array{aliases?: list<string>, required?: bool}> field => definition
     */
    abstract public function fields(): array;

    /**
     * @param  array<int, array<string, string>>  $rows  row number => raw row
     */
    abstract public function migrateGroup(MigrationContext $context, LegacySheet $sheet, string $group, array $rows): void;

    /**
     * Rows are grouped (one transaction each) by this field's value.
     */
    public function groupField(): string
    {
        return 'project';
    }

    /**
     * Whether a header the mapper does not declare is still meaningful
     * (ranking date columns). Default: it is unmapped.
     */
    public function acceptsDynamicColumn(string $header, MigrationContext $context): bool
    {
        return false;
    }

    /**
     * @param  array<string, string>  $columns
     */
    public function withColumns(array $columns): static
    {
        $clone = clone $this;
        $clone->columns = $columns;

        return $clone;
    }

    public function has(string $field): bool
    {
        return isset($this->columns[$field]);
    }

    /**
     * @param  array<string, string>  $row
     */
    public function value(array $row, string $field): string
    {
        $header = $this->columns[$field] ?? null;

        return $header === null ? '' : trim((string) ($row[$header] ?? ''));
    }

    /**
     * @param  array<string, string>  $row
     */
    public function blank(array $row, string $field): bool
    {
        return $this->value($row, $field) === '';
    }

    /**
     * @param  array<string, string>  $row
     */
    public function optional(array $row, string $field): ?string
    {
        $value = $this->value($row, $field);

        return $value === '' ? null : $value;
    }

    /**
     * Ensures required fields of a row are filled.
     *
     * @param  array<string, string>  $row
     */
    protected function requireValues(array $row, string ...$fields): void
    {
        foreach ($fields as $field) {
            if ($this->blank($row, $field)) {
                throw new InvalidArgumentException(ucfirst(str_replace('_', ' ', $field)).' is required.');
            }
        }
    }

    protected function project(MigrationContext $context, array $row): Project
    {
        return $context->projects->resolve($this->value($row, 'project'));
    }

    /**
     * The row's reporting month from an explicit period column, else from
     * the given date; an explicit period that disagrees with the date is
     * rejected rather than silently reassigned.
     */
    protected function period(MigrationContext $context, array $row, ?string $date = null): CyclePeriod
    {
        $explicit = $this->has('period') && ! $this->blank($row, 'period') ? $context->values->period($this->value($row, 'period')) : null;
        $fromDate = $date !== null ? CyclePeriod::fromDate(CarbonImmutable::parse($date)) : null;

        if ($explicit !== null && $fromDate !== null && ! $explicit->equals($fromDate)) {
            throw new InvalidArgumentException(sprintf('The date %s falls in %s but the row says period %s; fix the source instead of moving the record.', $date, $fromDate->label(), $explicit->label()));
        }

        return $explicit ?? $fromDate ?? throw new InvalidArgumentException('A reporting month is required: give a period (YYYY-MM) or a date.');
    }

    /**
     * The (unlocked) cycle for the row, or null when it is a conflict.
     */
    protected function cycle(MigrationContext $context, Project $project, CyclePeriod $period, LegacySheet $sheet, int $rowNumber): ?MonthlyCycle
    {
        return $context->cycles->resolve($context, $project, $period, $sheet->name, $rowNumber);
    }

    /**
     * Records a ledger-backed CREATE, or SKIPs when the fingerprint was
     * migrated by an earlier run of the same source.
     *
     * @return bool true when the caller should create the record
     */
    protected function shouldCreate(MigrationContext $context, LegacySheet $sheet, int $rowNumber, string $entity, string $fingerprint): bool
    {
        $existing = $context->ledger->find($sheet->name, $fingerprint);

        if ($existing !== null) {
            $context->tally($entity, MigrationOutcome::SKIP);
            $context->info($sheet->name, $rowNumber, $entity, sprintf('Already migrated by run #%d as %s [%d]; skipped.', $existing->legacy_migration_run_id, $existing->entity_type, $existing->entity_id));

            return false;
        }

        return true;
    }
}
