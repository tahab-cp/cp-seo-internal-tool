<?php

namespace App\Support\Imports;

use App\Models\ImportRowError;

/**
 * A validation error or warning on one CSV row. Errors block the row
 * (and therefore the whole atomic import); warnings never block.
 */
final readonly class RowIssue
{
    public function __construct(
        public int $rowNumber,
        public ?string $field,
        public string $severity,
        public string $message,
    ) {}

    public static function error(int $rowNumber, ?string $field, string $message): self
    {
        return new self($rowNumber, $field, ImportRowError::SEVERITY_ERROR, $message);
    }

    public static function warning(int $rowNumber, ?string $field, string $message): self
    {
        return new self($rowNumber, $field, ImportRowError::SEVERITY_WARNING, $message);
    }

    public function isError(): bool
    {
        return $this->severity === ImportRowError::SEVERITY_ERROR;
    }

    public function isWarning(): bool
    {
        return $this->severity === ImportRowError::SEVERITY_WARNING;
    }
}
