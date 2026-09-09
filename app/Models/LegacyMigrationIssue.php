<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegacyMigrationIssue extends Model
{
    public const SEVERITY_INFO = 'info';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_ERROR = 'error';

    public const UPDATED_AT = null;

    protected $fillable = [
        'legacy_migration_run_id',
        'source_sheet',
        'source_row',
        'severity',
        'entity_type',
        'message',
        'raw_data_json',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'source_row' => 'integer',
            'raw_data_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(LegacyMigrationRun::class, 'legacy_migration_run_id');
    }
}
