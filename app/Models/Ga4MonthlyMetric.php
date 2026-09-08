<?php

namespace App\Models;

use App\Enums\DataSource;
use App\Models\Concerns\BelongsToMonthlyCycle;
use Database\Factories\Ga4MonthlyMetricFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The month's Google Analytics 4 summary (Website Traffic report section).
 * Exactly one per MonthlyCycle. engagement_rate is a human percentage
 * (62.50 = 62.5%), the same convention as GSC ctr.
 */
class Ga4MonthlyMetric extends Model
{
    /** @use HasFactory<Ga4MonthlyMetricFactory> */
    use BelongsToMonthlyCycle, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'monthly_cycle_id',
        'active_users',
        'new_users',
        'sessions',
        'organic_sessions',
        'engaged_sessions',
        'engagement_rate',
        'average_engagement_time_seconds',
        'event_count',
        'key_events',
        'source',
        'synced_at',
        'entered_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active_users' => 'integer',
            'new_users' => 'integer',
            'sessions' => 'integer',
            'organic_sessions' => 'integer',
            'engaged_sessions' => 'integer',
            'engagement_rate' => 'decimal:2',
            'average_engagement_time_seconds' => 'integer',
            'event_count' => 'integer',
            'key_events' => 'integer',
            'source' => DataSource::class,
            'synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }
}
