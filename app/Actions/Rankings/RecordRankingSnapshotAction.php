<?php

namespace App\Actions\Rankings;

use App\Exceptions\RankingSnapshotCycleConflictException;
use App\Models\Project;
use App\Models\RankingSnapshot;
use App\Services\Rankings\RankingSnapshotGuard;
use Illuminate\Support\Facades\DB;

class RecordRankingSnapshotAction
{
    public function __construct(
        protected RankingSnapshotGuard $guard,
    ) {}

    /**
     * Record one ranking observation.
     *
     * Duplicate rule: an observation is identified by keyword + checked_at +
     * source. Recording the same observation again *updates* its position
     * and URL instead of creating a duplicate or throwing. No history is
     * lost because the same moment is the same observation. The rule is
     * identical for bulk entry.
     *
     * The reporting month is never changed by this path: if the existing
     * observation belongs to a different cycle the call is rejected. Moving
     * an observation between months is a deliberate correction
     * (UpdateRankingSnapshotAction) that validates both cycles.
     *
     * @param  array<string, mixed>  $attributes  keyword_id, monthly_cycle_id, checked_at, position, ranking_url, source
     */
    public function handle(Project $project, array $attributes): RankingSnapshot
    {
        return DB::transaction(function () use ($project, $attributes): RankingSnapshot {
            $keyword = $this->guard->resolveKeyword($project, $attributes['keyword_id'] ?? null);
            $cycle = $this->guard->resolveCycle($project, $attributes['monthly_cycle_id'] ?? null);
            $this->guard->ensureCycleNotLocked($cycle, 'record rankings in it');

            $checkedAt = $this->guard->normaliseCheckedAt($attributes['checked_at'] ?? null);
            $source = $this->guard->normaliseSource($attributes['source'] ?? null);
            $position = $this->guard->normalisePosition($attributes['position'] ?? null);
            $url = $this->guard->normaliseUrl($attributes['ranking_url'] ?? null);

            $existing = RankingSnapshot::query()
                ->where('keyword_id', $keyword->getKey())
                ->where('checked_at', $checkedAt)
                ->where('source', $source->value)
                ->first();

            if ($existing !== null) {
                $this->guard->ensureSnapshotNotLocked($existing, 'update rankings in it');

                if ((int) $existing->monthly_cycle_id !== (int) $cycle->getKey()) {
                    throw RankingSnapshotCycleConflictException::for($existing, $cycle);
                }

                $existing->fill([
                    'position' => $position,
                    'ranking_url' => $url,
                ])->save();

                return $existing;
            }

            return RankingSnapshot::query()->create([
                'keyword_id' => $keyword->getKey(),
                'monthly_cycle_id' => $cycle->getKey(),
                'checked_at' => $checkedAt,
                'position' => $position,
                'ranking_url' => $url,
                'source' => $source,
            ]);
        });
    }
}
