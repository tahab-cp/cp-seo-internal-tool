<?php

namespace App\Actions\Analytics;

use App\Models\Ga4MonthlyMetric;
use App\Models\MonthlyCycle;
use App\Models\User;
use App\Services\Analytics\AnalyticsIntegrityGuard;
use Illuminate\Support\Facades\DB;

class SaveGa4MonthlyMetricsAction
{
    public function __construct(
        protected AnalyticsIntegrityGuard $guard,
    ) {}

    /**
     * Create or update the month's single GA4 summary (manual entry).
     *
     * @param  array<string, mixed>  $attributes  active_users, new_users, sessions, organic_sessions, engaged_sessions, engagement_rate, average_engagement_time_seconds, event_count, key_events, source
     */
    public function handle(MonthlyCycle $cycle, array $attributes, User $enteredBy): Ga4MonthlyMetric
    {
        return DB::transaction(function () use ($cycle, $attributes, $enteredBy): Ga4MonthlyMetric {
            $this->guard->ensureCycleNotLocked($cycle, 'change its Google Analytics summary');
            $this->guard->ensureEnteredBy($cycle, $enteredBy);

            $metric = Ga4MonthlyMetric::query()->firstOrNew(['monthly_cycle_id' => $cycle->getKey()]);

            $metric->fill([
                'active_users' => $this->guard->normaliseCount($attributes['active_users'] ?? null, 'active users'),
                'new_users' => $this->guard->normaliseCount($attributes['new_users'] ?? null, 'new users'),
                'sessions' => $this->guard->normaliseCount($attributes['sessions'] ?? null, 'sessions'),
                'organic_sessions' => $this->guard->normaliseCount($attributes['organic_sessions'] ?? null, 'organic sessions'),
                'engaged_sessions' => $this->guard->normaliseCount($attributes['engaged_sessions'] ?? null, 'engaged sessions'),
                'engagement_rate' => $this->guard->normalisePercentage($attributes['engagement_rate'] ?? null, 'engagement rate'),
                'average_engagement_time_seconds' => $this->guard->normaliseCount($attributes['average_engagement_time_seconds'] ?? null, 'average engagement time'),
                'event_count' => $this->guard->normaliseCount($attributes['event_count'] ?? null, 'event count'),
                'key_events' => $this->guard->normaliseCount($attributes['key_events'] ?? null, 'key events'),
                'source' => $this->guard->normaliseManualSource($attributes['source'] ?? null),
                'synced_at' => null,
                'entered_by' => $enteredBy->getKey(),
            ]);

            $metric->save();

            return $metric;
        });
    }
}
