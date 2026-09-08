<?php

namespace App\Actions\Analytics;

use App\Models\AuthorityMetric;
use App\Models\MonthlyCycle;
use App\Models\User;
use App\Services\Analytics\AnalyticsIntegrityGuard;
use Illuminate\Support\Facades\DB;

class SaveAuthorityMetricsAction
{
    public function __construct(
        protected AnalyticsIntegrityGuard $guard,
    ) {}

    /**
     * Create or update the month's single site-authority snapshot (manual
     * entry). These are vendor totals; they never touch the operational
     * backlinks target.
     *
     * @param  array<string, mixed>  $attributes  moz_domain_authority, moz_linking_root_domains, ahrefs_domain_rating, ahrefs_url_rating, backlinks_count, referring_domains_count, notes, source
     */
    public function handle(MonthlyCycle $cycle, array $attributes, User $enteredBy): AuthorityMetric
    {
        return DB::transaction(function () use ($cycle, $attributes, $enteredBy): AuthorityMetric {
            $this->guard->ensureCycleNotLocked($cycle, 'change its authority metrics');
            $this->guard->ensureEnteredBy($cycle, $enteredBy);

            $metric = AuthorityMetric::query()->firstOrNew(['monthly_cycle_id' => $cycle->getKey()]);

            $metric->fill([
                'moz_domain_authority' => $this->guard->normaliseScore($attributes['moz_domain_authority'] ?? null, 'Moz domain authority', integer: true),
                'moz_linking_root_domains' => $this->guard->normaliseCount($attributes['moz_linking_root_domains'] ?? null, 'Moz linking root domains'),
                'ahrefs_domain_rating' => $this->guard->normaliseScore($attributes['ahrefs_domain_rating'] ?? null, 'Ahrefs domain rating'),
                'ahrefs_url_rating' => $this->guard->normaliseScore($attributes['ahrefs_url_rating'] ?? null, 'Ahrefs URL rating'),
                'backlinks_count' => $this->guard->normaliseCount($attributes['backlinks_count'] ?? null, 'backlinks count'),
                'referring_domains_count' => $this->guard->normaliseCount($attributes['referring_domains_count'] ?? null, 'referring domains count'),
                'notes' => $this->guard->normaliseText($attributes['notes'] ?? null, 5000, 'notes'),
                'source' => $this->guard->normaliseManualSource($attributes['source'] ?? null),
                'synced_at' => null,
                'entered_by' => $enteredBy->getKey(),
            ]);

            $metric->save();

            return $metric;
        });
    }
}
