<?php

namespace App\Models;

use App\Enums\DataSource;
use App\Models\Concerns\BelongsToMonthlyCycle;
use Database\Factories\GscMonthlyMetricFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The month's Google Search Console summary (Organic Search report section).
 * Exactly one per MonthlyCycle. ctr is a human percentage (8.50 = 8.5%).
 */
class GscMonthlyMetric extends Model
{
    /** @use HasFactory<GscMonthlyMetricFactory> */
    use BelongsToMonthlyCycle, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'monthly_cycle_id',
        'clicks',
        'impressions',
        'ctr',
        'average_position',
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
            'clicks' => 'integer',
            'impressions' => 'integer',
            'ctr' => 'decimal:2',
            'average_position' => 'decimal:2',
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
