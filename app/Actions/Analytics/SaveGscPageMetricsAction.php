<?php

namespace App\Actions\Analytics;

use App\Models\GscPageMetric;
use App\Models\MonthlyCycle;
use App\Models\User;
use App\Services\Analytics\AnalyticsIntegrityGuard;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class SaveGscPageMetricsAction
{
    public function __construct(
        protected AnalyticsIntegrityGuard $guard,
    ) {}

    /**
     * Replace the month's landing-pages dataset. Identity is the cycle plus
     * the page URL: matching rows are updated in place, new ones inserted,
     * omitted ones removed. page_url is always stored; page_id is an
     * optional mapping to a same-project Page and never alters that Page.
     *
     * @param  list<array<string, mixed>>  $rows  page_url, page_id, clicks, impressions, ctr, average_position
     * @return Collection<int, GscPageMetric>
     */
    public function handle(MonthlyCycle $cycle, array $rows, User $enteredBy): Collection
    {
        return DB::transaction(function () use ($cycle, $rows, $enteredBy): Collection {
            $this->guard->ensureCycleNotLocked($cycle, 'change its Search Console landing pages');
            $this->guard->ensureEnteredBy($cycle, $enteredBy);

            $project = $cycle->project;
            $prepared = [];

            foreach (array_values($rows) as $row) {
                $url = $this->guard->normaliseUrl($row['page_url'] ?? null);
                $label = "page {$url}";

                $prepared[] = [
                    'page_url' => $url,
                    'page_id' => $this->guard->resolvePage($project, $row['page_id'] ?? null)?->getKey(),
                    'clicks' => $this->guard->normaliseCount($row['clicks'] ?? null, "clicks for {$label}", required: true),
                    'impressions' => $this->guard->normaliseCount($row['impressions'] ?? null, "impressions for {$label}", required: true),
                    'ctr' => $this->guard->normalisePercentage($row['ctr'] ?? null, "CTR for {$label}"),
                    'average_position' => $this->guard->normalisePosition($row['average_position'] ?? null, "average position for {$label}"),
                ];
            }

            $this->guard->ensureDistinct(array_map(fn (array $r): string => $this->guard->identityKey($r['page_url']), $prepared), 'page URL');

            $existing = $cycle->gscPageMetrics()->get()->keyBy(fn (GscPageMetric $m): string => $this->guard->identityKey($m->page_url));
            $kept = [];

            foreach ($prepared as $attributes) {
                $key = $this->guard->identityKey($attributes['page_url']);
                $metric = $existing->get($key) ?? new GscPageMetric(['monthly_cycle_id' => $cycle->getKey()]);
                $metric->fill($attributes)->save();
                $kept[] = $metric->getKey();
            }

            $cycle->gscPageMetrics()->whereKeyNot($kept)->delete();

            return $cycle->gscPageMetrics()->orderByDesc('clicks')->orderBy('page_url')->get();
        });
    }
}
