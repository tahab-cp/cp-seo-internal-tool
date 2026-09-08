<?php

namespace App\Actions\Rankings;

use App\Models\Project;
use App\Models\RankingSnapshot;
use App\Services\Rankings\RankingSnapshotGuard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RecordRankingSnapshotsAction
{
    public function __construct(
        protected RankingSnapshotGuard $guard,
        protected RecordRankingSnapshotAction $recordSnapshot,
    ) {}

    /**
     * Bulk ranking entry: one observation per row for a shared cycle,
     * moment and source. Atomic — any invalid row (foreign keyword, bad
     * position, locked cycle…) rolls back the whole batch. Rows whose
     * observation already exists are updated (same rule as single entry).
     *
     * @param  array{monthly_cycle_id: int|string, checked_at: mixed, source?: mixed}  $context
     * @param  list<array{keyword_id: int|string, position?: mixed, ranking_url?: mixed}>  $rows
     * @return Collection<int, RankingSnapshot>
     */
    public function handle(Project $project, array $context, array $rows): Collection
    {
        return DB::transaction(function () use ($project, $context, $rows): Collection {
            $cycle = $this->guard->resolveCycle($project, $context['monthly_cycle_id'] ?? null);
            $this->guard->ensureCycleNotLocked($cycle, 'record rankings in it');

            $checkedAt = $this->guard->normaliseCheckedAt($context['checked_at'] ?? null);
            $source = $this->guard->normaliseSource($context['source'] ?? null);

            $seen = [];

            return collect(array_values($rows))->map(function (array $row, int $index) use ($project, $cycle, $checkedAt, $source, &$seen): RankingSnapshot {
                $keywordId = (int) ($row['keyword_id'] ?? 0);

                if (isset($seen[$keywordId])) {
                    throw new InvalidArgumentException("Row {$index}: keyword [{$keywordId}] appears more than once in the batch.");
                }

                $seen[$keywordId] = true;

                return $this->recordSnapshot->handle($project, [
                    'keyword_id' => $keywordId,
                    'monthly_cycle_id' => $cycle->getKey(),
                    'checked_at' => $checkedAt,
                    'source' => $source,
                    'position' => $row['position'] ?? null,
                    'ranking_url' => $row['ranking_url'] ?? null,
                ]);
            });
        });
    }
}
