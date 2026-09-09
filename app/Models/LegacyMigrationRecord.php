<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ledger entry: which application record a legacy source row became.
 * Reruns look the fingerprint up and skip rows already migrated.
 */
class LegacyMigrationRecord extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'legacy_migration_run_id',
        'source_identifier',
        'source_sheet',
        'source_row',
        'fingerprint',
        'entity_type',
        'entity_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'source_row' => 'integer',
            'entity_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(LegacyMigrationRun::class, 'legacy_migration_run_id');
    }
}
