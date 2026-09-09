<?php

namespace App\Support\LegacyMigration;

use App\Models\LegacyMigrationIssue;

final readonly class MigrationIssue
{
    public function __construct(
        public string $severity,
        public string $sheet,
        public ?int $row,
        public ?string $entity,
        public string $message,
        public ?array $raw = null,
    ) {}

    public static function info(string $sheet, ?int $row, ?string $entity, string $message, ?array $raw = null): self
    {
        return new self(LegacyMigrationIssue::SEVERITY_INFO, $sheet, $row, $entity, $message, $raw);
    }

    public static function warning(string $sheet, ?int $row, ?string $entity, string $message, ?array $raw = null): self
    {
        return new self(LegacyMigrationIssue::SEVERITY_WARNING, $sheet, $row, $entity, $message, $raw);
    }

    public static function error(string $sheet, ?int $row, ?string $entity, string $message, ?array $raw = null): self
    {
        return new self(LegacyMigrationIssue::SEVERITY_ERROR, $sheet, $row, $entity, $message, $raw);
    }

    public function isWarning(): bool
    {
        return $this->severity === LegacyMigrationIssue::SEVERITY_WARNING;
    }

    public function isError(): bool
    {
        return $this->severity === LegacyMigrationIssue::SEVERITY_ERROR;
    }

    public function toArray(): array
    {
        return [
            'severity' => $this->severity,
            'sheet' => $this->sheet,
            'row' => $this->row,
            'entity' => $this->entity,
            'message' => $this->message,
        ];
    }
}
