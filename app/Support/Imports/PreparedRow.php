<?php

namespace App\Support\Imports;

/**
 * The outcome of preparing one CSV row: the normalised data the importer
 * will hand to the domain actions (null when the row has errors), the
 * mapped raw values for display, and the row's issues.
 */
final class PreparedRow
{
    /**
     * @param  array<string, string>  $values  mapped raw CSV values by field key (escaped by the view)
     * @param  array<string, mixed>|null  $data  normalised payload, null when the row is invalid
     * @param  list<RowIssue>  $issues
     */
    public function __construct(
        public readonly int $rowNumber,
        public readonly array $values,
        public ?array $data,
        public array $issues = [],
    ) {}

    public function addError(?string $field, string $message): void
    {
        $this->issues[] = RowIssue::error($this->rowNumber, $field, $message);
        $this->data = null;
    }

    public function addWarning(?string $field, string $message): void
    {
        $this->issues[] = RowIssue::warning($this->rowNumber, $field, $message);
    }

    public function isValid(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->isError()) {
                return false;
            }
        }

        return $this->data !== null;
    }

    public function hasWarnings(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->isWarning()) {
                return true;
            }
        }

        return false;
    }
}
