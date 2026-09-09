<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One validation problem on one CSV row of an import batch. Kept after
 * the attempt so a failed import stays reviewable.
 */
class ImportRowError extends Model
{
    public const SEVERITY_ERROR = 'error';

    public const SEVERITY_WARNING = 'warning';

    public const UPDATED_AT = null;

    protected $fillable = [
        'import_batch_id',
        'row_number',
        'field',
        'severity',
        'message',
        'raw_row_json',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'row_number' => 'integer',
            'raw_row_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    public function isWarning(): bool
    {
        return $this->severity === self::SEVERITY_WARNING;
    }
}
