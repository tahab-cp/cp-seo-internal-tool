<?php

namespace App\Models;

use App\Enums\ReportSectionKey;
use App\Enums\ReportSectionStatus;
use Database\Factories\MonthlyReportSectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A snapshot of one ProjectReportSection taken when its monthly report was
 * created. Later project configuration changes never touch these rows.
 * `status` is a display cache; ReportReadinessService is authoritative.
 */
class MonthlyReportSection extends Model
{
    /** @use HasFactory<MonthlyReportSectionFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'monthly_report_id',
        'section_key',
        'title',
        'is_enabled',
        'is_required',
        'status',
        'sort_order',
        'custom_text',
        'settings_json',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'section_key' => ReportSectionKey::class,
            'is_enabled' => 'boolean',
            'is_required' => 'boolean',
            'status' => ReportSectionStatus::class,
            'sort_order' => 'integer',
            'settings_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<MonthlyReport, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(MonthlyReport::class, 'monthly_report_id');
    }

    public function participates(): bool
    {
        return $this->is_enabled;
    }

    public function blocksReadiness(): bool
    {
        return $this->is_enabled && $this->is_required;
    }
}
