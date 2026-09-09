<?php

namespace App\Models;

use App\Models\Concerns\ImmutableRecord;
use Database\Factories\MonthlyReportRevisionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A superseded FINAL report: the exact snapshot and PDF that were final
 * until a Super Admin unlocked the month for correction. Read-only
 * evidence, addressable forever; never rebuilt from live data.
 */
class MonthlyReportRevision extends Model
{
    /** @use HasFactory<MonthlyReportRevisionFactory> */
    use HasFactory, ImmutableRecord;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'monthly_report_id',
        'version',
        'snapshot_json',
        'generated_pdf_path',
        'generated_at',
        'finalized_at',
        'finalized_by',
        'archived_at',
        'archived_by',
        'unlock_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'snapshot_json' => 'array',
            'generated_at' => 'datetime',
            'finalized_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MonthlyReport, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(MonthlyReport::class, 'monthly_report_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    public function hasPdf(): bool
    {
        return filled($this->generated_pdf_path);
    }

    public function versionLabel(): string
    {
        return 'v'.$this->version;
    }
}
