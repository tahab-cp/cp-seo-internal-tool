<?php

namespace App\Actions\Analytics;

use App\Models\GscQueryMetric;
use App\Models\MonthlyCycle;
use App\Models\User;
use App\Services\Analytics\AnalyticsIntegrityGuard;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class SaveGscQueryMetricsAction
{
    public function __construct(
        protected AnalyticsIntegrityGuard $guard,
    ) {}

    /**
     * Replace the month's top-queries dataset. Identity is the cycle plus
     * the normalised query: matching rows are updated in place, new ones
     * inserted, omitted ones removed. Never touches tracked Keywords.
     *
     * @param  list<array<string, mixed>>  $rows  query, clicks, impressions, ctr, average_position
     * @return Collection<int, GscQueryMetric>
     */
    public function handle(MonthlyCycle $cycle, array $rows, User $enteredBy): Collection
    {
        return DB::transaction(function () use ($cycle, $rows, $enteredBy): Collection {
            $this->guard->ensureCycleNotLocked($cycle, 'change its Search Console queries');
            $this->guard->ensureEnteredBy($cycle, $enteredBy);

            $prepared = [];

            foreach (array_values($rows) as $index => $row) {
                $query = $this->guard->normaliseQuery($row['query'] ?? null);
                $label = "query “{$query}”";

                $prepared[] = [
                    'query' => $query,
                    'clicks' => $this->guard->normaliseCount($row['clicks'] ?? null, "clicks for {$label}", required: true),
                    'impressions' => $this->guard->normaliseCount($row['impressions'] ?? null, "impressions for {$label}", required: true),
                    'ctr' => $this->guard->normalisePercentage($row['ctr'] ?? null, "CTR for {$label}"),
                    'average_position' => $this->guard->normalisePosition($row['average_position'] ?? null, "average position for {$label}"),
                ];
            }

            $this->guard->ensureDistinct(array_column($prepared, 'query'), 'query');

            $existing = $cycle->gscQueryMetrics()->get()->keyBy(fn (GscQueryMetric $m): string => $this->guard->identityKey($m->query));
            $kept = [];

            foreach ($prepared as $attributes) {
                $key = $this->guard->identityKey($attributes['query']);
                $metric = $existing->get($key) ?? new GscQueryMetric(['monthly_cycle_id' => $cycle->getKey()]);
                $metric->fill($attributes)->save();
                $kept[] = $metric->getKey();
            }

            $cycle->gscQueryMetrics()->whereKeyNot($kept)->delete();

            return $cycle->gscQueryMetrics()->orderByDesc('clicks')->orderBy('query')->get();
        });
    }
}
