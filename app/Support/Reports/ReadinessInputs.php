<?php

namespace App\Support\Reports;

/**
 * Everything ReportReadinessService needs to know about one reporting
 * month, loaded ahead of time (in batch for dashboards) so the rules can
 * be applied without touching the database again. The RULES live in the
 * service; this object only carries facts about the source data.
 */
final readonly class ReadinessInputs
{
    public function __construct(
        public int $cycleId,
        public ?string $executiveSummary,
        public bool $hasAuthorityMetric,
        public bool $hasGscSummary,
        public bool $hasGa4Summary,
        public int $gscQueryCount,
        public int $gscPageCount,
        public int $ga4CountryCount,
        public bool $hasRecommendationNote,
        public bool $hasBacklinks,
        public bool $hasBacklinkTargetSnapshot,
        public int $activeKeywordCount,
        public int $activeKeywordsWithSnapshot,
    ) {}

    public function hasExecutiveSummary(): bool
    {
        return trim((string) $this->executiveSummary) !== '';
    }

    public function activeKeywordsWithoutSnapshot(): int
    {
        return max(0, $this->activeKeywordCount - $this->activeKeywordsWithSnapshot);
    }
}
