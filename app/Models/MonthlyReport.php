<?php

namespace App\Models;

use App\Enums\ReportStatus;
use App\Models\Concerns\BelongsToMonthlyCycle;
use Database\Factories\MonthlyReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The single report for a reporting month. Created as a draft with a
 * snapshot of the project's section configuration. Finalisation, the
 * report snapshot and the PDF arrive in Milestone 13.
 */
class MonthlyReport extends Model
{
    /** @use HasFactory<MonthlyReportFactory> */
    use BelongsToMonthlyCycle, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'monthly_cycle_id',
        'status',
        'executive_summary',
        'review_notes',
        'snapshot_json',
        'generated_pdf_path',
        'generated_at',
        'finalized_at',
        'finalized_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReportStatus::class,
            'snapshot_json' => 'array',
            'generated_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<MonthlyReportSection, $this>
     */
    public function sections(): HasMany
    {
        return $this->hasMany(MonthlyReportSection::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function isDraft(): bool
    {
        return $this->status->isDraft();
    }

    public function hasExecutiveSummary(): bool
    {
        return trim((string) $this->executive_summary) !== '';
    }
}
