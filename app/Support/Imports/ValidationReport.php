<?php

namespace App\Support\Imports;

use Illuminate\Support\Collection;

/**
 * Result of validating every row of a batch without writing anything.
 */
final readonly class ValidationReport
{
    /**
     * @param  Collection<int, PreparedRow>  $rows  every data row, in file order
     */
    public function __construct(
        public Collection $rows,
    ) {}

    public function totalRows(): int
    {
        return $this->rows->count();
    }

    /**
     * @return Collection<int, PreparedRow>
     */
    public function validRows(): Collection
    {
        return $this->rows->filter(fn (PreparedRow $row): bool => $row->isValid())->values();
    }

    public function validCount(): int
    {
        return $this->validRows()->count();
    }

    public function invalidCount(): int
    {
        return $this->totalRows() - $this->validCount();
    }

    /**
     * @return Collection<int, RowIssue>
     */
    public function issues(): Collection
    {
        return $this->rows->flatMap(fn (PreparedRow $row): array => $row->issues)->values();
    }

    public function errorCount(): int
    {
        return $this->issues()->filter(fn (RowIssue $issue): bool => $issue->isError())->count();
    }

    public function warningCount(): int
    {
        return $this->issues()->filter(fn (RowIssue $issue): bool => $issue->isWarning())->count();
    }

    public function isImportable(): bool
    {
        return $this->totalRows() > 0 && $this->invalidCount() === 0;
    }

    /**
     * @return Collection<int, PreparedRow>
     */
    public function sample(int $limit): Collection
    {
        return $this->rows->take($limit)->values();
    }
}
