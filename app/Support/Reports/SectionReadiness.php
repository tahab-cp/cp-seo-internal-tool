<?php

namespace App\Support\Reports;

use App\Enums\ReportSectionKey;

/**
 * Live readiness of one snapshotted report section.
 */
final readonly class SectionReadiness
{
    public function __construct(
        public ReportSectionKey $key,
        public string $title,
        public bool $enabled,
        public bool $required,
        public bool $complete,
        public ?string $reason,
        public int $sortOrder,
    ) {}

    /**
     * Enabled + required sections are the only ones that gate readiness.
     */
    public function blocksReadiness(): bool
    {
        return $this->enabled && $this->required && ! $this->complete;
    }

    public function counts(): bool
    {
        return $this->enabled && $this->required;
    }

    /**
     * @return array{key: string, title: string, enabled: bool, required: bool, complete: bool, reason: ?string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key->value,
            'title' => $this->title,
            'enabled' => $this->enabled,
            'required' => $this->required,
            'complete' => $this->complete,
            'reason' => $this->reason,
        ];
    }
}
