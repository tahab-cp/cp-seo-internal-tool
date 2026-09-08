<?php

namespace App\Actions\Analytics;

use App\Models\Ga4CountryMetric;
use App\Models\MonthlyCycle;
use App\Models\User;
use App\Services\Analytics\AnalyticsIntegrityGuard;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class SaveGa4CountryMetricsAction
{
    public function __construct(
        protected AnalyticsIntegrityGuard $guard,
    ) {}

    /**
     * Replace the month's audience-by-country dataset. Identity is the
     * cycle plus the country (case-insensitive): matching rows are updated
     * in place, new ones inserted, omitted ones removed.
     *
     * @param  list<array<string, mixed>>  $rows  country, active_users, new_users, sessions, engaged_sessions, engagement_rate, event_count, key_events
     * @return Collection<int, Ga4CountryMetric>
     */
    public function handle(MonthlyCycle $cycle, array $rows, User $enteredBy): Collection
    {
        return DB::transaction(function () use ($cycle, $rows, $enteredBy): Collection {
            $this->guard->ensureCycleNotLocked($cycle, 'change its audience by country');
            $this->guard->ensureEnteredBy($cycle, $enteredBy);

            $prepared = [];

            foreach (array_values($rows) as $row) {
                $country = $this->guard->normaliseCountry($row['country'] ?? null);
                $label = "country {$country}";

                $prepared[] = [
                    'country' => $country,
                    'active_users' => $this->guard->normaliseCount($row['active_users'] ?? null, "active users for {$label}"),
                    'new_users' => $this->guard->normaliseCount($row['new_users'] ?? null, "new users for {$label}"),
                    'sessions' => $this->guard->normaliseCount($row['sessions'] ?? null, "sessions for {$label}"),
                    'engaged_sessions' => $this->guard->normaliseCount($row['engaged_sessions'] ?? null, "engaged sessions for {$label}"),
                    'engagement_rate' => $this->guard->normalisePercentage($row['engagement_rate'] ?? null, "engagement rate for {$label}"),
                    'event_count' => $this->guard->normaliseCount($row['event_count'] ?? null, "event count for {$label}"),
                    'key_events' => $this->guard->normaliseCount($row['key_events'] ?? null, "key events for {$label}"),
                ];
            }

            $this->guard->ensureDistinct(array_map(fn (array $r): string => $this->guard->identityKey($r['country']), $prepared), 'country');

            $existing = $cycle->ga4CountryMetrics()->get()->keyBy(fn (Ga4CountryMetric $m): string => $this->guard->identityKey($m->country));
            $kept = [];

            foreach ($prepared as $attributes) {
                $key = $this->guard->identityKey($attributes['country']);
                $metric = $existing->get($key) ?? new Ga4CountryMetric(['monthly_cycle_id' => $cycle->getKey()]);
                $metric->fill($attributes)->save();
                $kept[] = $metric->getKey();
            }

            $cycle->ga4CountryMetrics()->whereKeyNot($kept)->delete();

            return $cycle->ga4CountryMetrics()->orderByDesc('active_users')->orderBy('country')->get();
        });
    }
}
