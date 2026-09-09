<?php

namespace App\Models;

use App\Enums\AuditEventType;
use App\Models\Concerns\ImmutableRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable lifecycle event for a reporting month (finalized, unlocked
 * for correction, marked ready). Written by the domain actions only.
 */
class MonthlyCycleAuditEvent extends Model
{
    use ImmutableRecord;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'monthly_cycle_id',
        'monthly_report_id',
        'user_id',
        'event_type',
        'reason',
        'metadata_json',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_type' => AuditEventType::class,
            'metadata_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<MonthlyCycle, $this>
     */
    public function monthlyCycle(): BelongsTo
    {
        return $this->belongsTo(MonthlyCycle::class);
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function version(): ?int
    {
        return isset($this->metadata_json['version']) ? (int) $this->metadata_json['version'] : null;
    }

    /**
     * @param  Builder<MonthlyCycleAuditEvent>  $query
     * @return Builder<MonthlyCycleAuditEvent>
     */
    public function scopeChronological(Builder $query): Builder
    {
        return $query->orderBy('created_at')->orderBy('id');
    }
}
