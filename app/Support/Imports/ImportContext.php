<?php

namespace App\Support\Imports;

use App\Models\ImportBatch;
use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Models\User;

/**
 * Everything an importer needs to know about WHERE rows go and WHO is
 * importing. Project and cycle are resolved through project visibility
 * before this object exists; importers never re-derive them from CSV data.
 */
final readonly class ImportContext
{
    public function __construct(
        public ImportBatch $batch,
        public Project $project,
        public ?MonthlyCycle $cycle,
        public User $user,
    ) {}

    public function cycleId(): ?int
    {
        return $this->cycle === null ? null : (int) $this->cycle->getKey();
    }
}
