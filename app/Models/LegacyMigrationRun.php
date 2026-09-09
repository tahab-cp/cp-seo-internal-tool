<?php

namespace App\Models;

use App\Enums\LegacyMigrationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One legacy-migration run: the manifest entry that lets the same source
 * workbook be recognised (checksum) and every rerun be reviewed.
 */
class LegacyMigrationRun extends Model
{
    public const MODE_DRY_RUN = 'dry-run';

    public const MODE_APPLY = 'apply';

    protected $fillable = [
        'source_identifier',
        'source_filename',
        'source_checksum',
        'mapper_version',
        'mode',
        'status',
        'created_by',
        'total_items',
        'created_items',
        'updated_items',
        'skipped_items',
        'conflict_items',
        'warning_count',
        'error_count',
        'metadata_json',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => LegacyMigrationStatus::class,
            'metadata_json' => 'array',
            'total_items' => 'integer',
            'created_items' => 'integer',
            'updated_items' => 'integer',
            'skipped_items' => 'integer',
            'conflict_items' => 'integer',
            'warning_count' => 'integer',
            'error_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(LegacyMigrationIssue::class)->orderBy('id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(LegacyMigrationRecord::class);
    }

    public function isDryRun(): bool
    {
        return $this->mode === self::MODE_DRY_RUN;
    }
}
