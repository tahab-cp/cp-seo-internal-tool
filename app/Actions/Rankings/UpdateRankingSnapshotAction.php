<?php

namespace App\Actions\Rankings;

use App\Models\RankingSnapshot;
use App\Services\Rankings\RankingSnapshotGuard;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class UpdateRankingSnapshotAction
{
    public function __construct(
        protected RankingSnapshotGuard $guard,
    ) {}

    /**
     * Correct an existing observation in an open/reporting cycle. Moving it
     * onto another existing observation's identity (keyword + checked_at +
     * source) is refused rather than merged.
     *
     * @param  array<string, mixed>  $attributes  monthly_cycle_id, checked_at, position, ranking_url, source
     */
    public function handle(RankingSnapshot $snapshot, array $attributes): RankingSnapshot
    {
        return DB::transaction(function () use ($snapshot, $attributes): RankingSnapshot {
            $project = $snapshot->keyword->project;

            // Source and (possible) destination cycles are row-locked together,
            // in deterministic order, and re-checked fresh.
            $moving = array_key_exists('monthly_cycle_id', $attributes);
            $destination = $moving ? $this->guard->resolveCycle($project, $attributes['monthly_cycle_id']) : null;

            $this->guard->lockCyclesForMove(
                $snapshot->monthly_cycle_id,
                $moving ? $destination->getKey() : $snapshot->monthly_cycle_id,
                'edit rankings in it',
                'move rankings into it',
            );

            if ($moving) {
                $snapshot->monthly_cycle_id = $destination->getKey();
            }

            if (array_key_exists('checked_at', $attributes)) {
                $snapshot->checked_at = $this->guard->normaliseCheckedAt($attributes['checked_at']);
            }

            if (array_key_exists('source', $attributes)) {
                $snapshot->source = $this->guard->normaliseSource($attributes['source']);
            }

            if (array_key_exists('position', $attributes)) {
                $snapshot->position = $this->guard->normalisePosition($attributes['position']);
            }

            if (array_key_exists('ranking_url', $attributes)) {
                $snapshot->ranking_url = $this->guard->normaliseUrl($attributes['ranking_url']);
            }

            $collision = RankingSnapshot::query()
                ->whereKeyNot($snapshot->getKey())
                ->where('keyword_id', $snapshot->keyword_id)
                ->where('checked_at', $snapshot->checked_at)
                ->where('source', $snapshot->source->value)
                ->exists();

            if ($collision) {
                throw new InvalidArgumentException('Another observation already exists for this keyword at that moment and source; edit that one instead.');
            }

            $snapshot->save();

            return $snapshot;
        });
    }
}
