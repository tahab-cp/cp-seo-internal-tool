<?php

namespace App\Actions\Analytics;

use App\Models\GscMonthlyMetric;
use App\Models\MonthlyCycle;
use App\Models\User;
use App\Services\Analytics\AnalyticsIntegrityGuard;
use Illuminate\Support\Facades\DB;

class SaveGscMonthlyMetricsAction
{
    public function __construct(
        protected AnalyticsIntegrityGuard $guard,
    ) {}

    /**
     * Create or update the month's single GSC summary (manual entry).
     *
     * @param  array<string, mixed>  $attributes  clicks, impressions, ctr, average_position, source
     */
    public function handle(MonthlyCycle $cycle, array $attributes, User $enteredBy): GscMonthlyMetric
    {
        return DB::transaction(function () use ($cycle, $attributes, $enteredBy): GscMonthlyMetric {
            $this->guard->ensureCycleNotLocked($cycle, 'change its Search Console summary');
            $this->guard->ensureEnteredBy($cycle, $enteredBy);

            $metric = GscMonthlyMetric::query()->firstOrNew(['monthly_cycle_id' => $cycle->getKey()]);

            $metric->fill([
                'clicks' => $this->guard->normaliseCount($attributes['clicks'] ?? null, 'clicks', required: true),
                'impressions' => $this->guard->normaliseCount($attributes['impressions'] ?? null, 'impressions', required: true),
                'ctr' => $this->guard->normalisePercentage($attributes['ctr'] ?? null, 'CTR'),
                'average_position' => $this->guard->normalisePosition($attributes['average_position'] ?? null),
                'source' => $this->guard->normaliseManualSource($attributes['source'] ?? null),
                'synced_at' => null,
                'entered_by' => $enteredBy->getKey(),
            ]);

            $metric->save();

            return $metric;
        });
    }
}
