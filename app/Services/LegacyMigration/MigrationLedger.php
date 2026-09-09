<?php

namespace App\Services\LegacyMigration;

use App\Models\LegacyMigrationRecord;
use App\Models\LegacyMigrationRun;

/**
 * Remembers what each legacy source row became, keyed by a deterministic
 * fingerprint of the row's identity fields, so a rerun of the same source
 * skips rows that have no natural database identity.
 */
class MigrationLedger
{
    public function __construct(
        protected LegacyMigrationRun $run,
    ) {}

    /**
     * @param  array<int|string, mixed>  $parts  identity fields (order matters)
     */
    public static function fingerprint(array $parts): string
    {
        $normalised = array_map(fn ($part): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $part) ?? (string) $part)), $parts);

        return hash('sha256', json_encode($normalised, JSON_UNESCAPED_UNICODE));
    }

    public function find(string $sheet, string $fingerprint): ?LegacyMigrationRecord
    {
        return LegacyMigrationRecord::query()
            ->where('source_identifier', $this->run->source_identifier)
            ->where('source_sheet', $sheet)
            ->where('fingerprint', $fingerprint)
            ->first();
    }

    public function record(string $sheet, ?int $row, string $fingerprint, string $entityType, int $entityId): LegacyMigrationRecord
    {
        return LegacyMigrationRecord::query()->create([
            'legacy_migration_run_id' => $this->run->getKey(),
            'source_identifier' => $this->run->source_identifier,
            'source_sheet' => $sheet,
            'source_row' => $row,
            'fingerprint' => $fingerprint,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'created_at' => now(),
        ]);
    }
}
