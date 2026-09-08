<?php

namespace App\Actions\Keywords;

use App\Enums\KeywordStatus;
use App\Models\Keyword;
use App\Models\Project;
use App\Services\Rankings\RankingSnapshotGuard;
use Illuminate\Support\Facades\DB;

class CreateKeywordAction
{
    public function __construct(
        protected RankingSnapshotGuard $guard,
    ) {}

    /**
     * Track a keyword on a project.
     *
     * @param  array<string, mixed>  $attributes  keyword, location, target_page_id, keyword_role, search_volume, keyword_difficulty, search_intent, is_branded, status
     */
    public function handle(Project $project, array $attributes): Keyword
    {
        return DB::transaction(function () use ($project, $attributes): Keyword {
            // Caller keys win; location defaults to null so its normalised shadow is always set.
            $normalised = KeywordAttributes::normalise($attributes + ['location' => null]);

            if (! isset($normalised['keyword'])) {
                throw new \InvalidArgumentException('A keyword needs a value.');
            }

            KeywordAttributes::ensureUniqueWithin($project, $normalised['keyword_normalized'], $normalised['location_normalized']);

            $targetPage = $this->guard->resolveTargetPage($project, $attributes['target_page_id'] ?? null);

            $keyword = new Keyword($normalised + [
                'is_branded' => false,
                'status' => KeywordStatus::Active,
            ]);

            $keyword->project_id = $project->getKey();
            $keyword->target_page_id = $targetPage?->getKey();
            $keyword->save();

            return $keyword;
        });
    }
}
