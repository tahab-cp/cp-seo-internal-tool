<?php

namespace App\Services\Rankings;

use App\Enums\RankingSource;
use App\Models\Keyword;
use App\Models\MonthlyCycle;
use App\Models\Page;
use App\Models\Project;
use App\Models\RankingSnapshot;
use App\Services\MonthlyCycles\Concerns\LocksMonthlyCycles;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Domain rules for keywords and ranking observations, independent of forms:
 *
 * - keyword, target page and cycle must belong to the same project;
 * - locked cycles are immutable;
 * - a position is NULL (not ranking) or a positive integer, never 0;
 * - the source must be a known RankingSource.
 */
class RankingSnapshotGuard
{
    use LocksMonthlyCycles;

    public function resolveKeyword(Project $project, int|string|null $keywordId): Keyword
    {
        $keyword = filled($keywordId) ? Keyword::query()->find((int) $keywordId) : null;

        if ($keyword === null || (int) $keyword->project_id !== (int) $project->getKey()) {
            throw new InvalidArgumentException(sprintf(
                'Keyword [%s] does not belong to project "%s".',
                $keywordId ?? 'none',
                $project->name,
            ));
        }

        return $keyword;
    }

    public function resolveTargetPage(Project $project, int|string|null $pageId): ?Page
    {
        if ($pageId === null || $pageId === '') {
            return null;
        }

        $page = Page::withTrashed()->find((int) $pageId);

        if ($page === null || (int) $page->project_id !== (int) $project->getKey()) {
            throw new InvalidArgumentException(sprintf(
                'Target page [%s] does not belong to project "%s".',
                $pageId,
                $project->name,
            ));
        }

        return $page;
    }

    public function resolveCycle(Project $project, int|string|null $cycleId): MonthlyCycle
    {
        $cycle = filled($cycleId) ? MonthlyCycle::query()->find((int) $cycleId) : null;

        if ($cycle === null || (int) $cycle->project_id !== (int) $project->getKey()) {
            throw new InvalidArgumentException(sprintf(
                'Monthly cycle [%s] does not belong to project "%s".',
                $cycleId ?? 'none',
                $project->name,
            ));
        }

        return $cycle;
    }

    /**
     * Row-lock the cycle (inside the caller's transaction) and refuse if it
     * is locked.
     */
    public function ensureCycleNotLocked(MonthlyCycle $cycle, string $operation): void
    {
        $this->lockCycle($cycle, $operation);
    }

    public function ensureSnapshotNotLocked(RankingSnapshot $snapshot, string $operation): void
    {
        $this->lockCycle($snapshot->monthly_cycle_id, $operation);
    }

    /**
     * NULL / '' / "not ranking" → null; otherwise a positive integer.
     */
    public function normalisePosition(mixed $position): ?int
    {
        if ($position === null || $position === '' || (is_string($position) && strtolower(trim($position)) === 'not ranking')) {
            return null;
        }

        if (! is_numeric($position) || (int) $position != $position || (int) $position < 1) {
            throw new InvalidArgumentException("Ranking position must be a positive integer or empty for not ranking; [{$position}] given.");
        }

        return (int) $position;
    }

    public function normaliseSource(mixed $source): RankingSource
    {
        if ($source instanceof RankingSource) {
            return $source;
        }

        if ($source === null || $source === '') {
            return RankingSource::Manual;
        }

        return RankingSource::tryFrom((string) $source)
            ?? throw new InvalidArgumentException("Unknown ranking source [{$source}].");
    }

    public function normaliseCheckedAt(mixed $checkedAt): CarbonImmutable
    {
        if ($checkedAt === null || $checkedAt === '') {
            throw new InvalidArgumentException('A ranking observation needs the moment it was checked.');
        }

        return CarbonImmutable::parse($checkedAt);
    }

    public function normaliseUrl(mixed $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        if (mb_strlen($url) > 500 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('The ranking URL must be a valid URL of at most 500 characters.');
        }

        return $url;
    }
}
