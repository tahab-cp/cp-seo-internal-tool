<?php

namespace App\Actions\Keywords;

use App\Models\Keyword;
use App\Services\Rankings\RankingSnapshotGuard;
use Illuminate\Support\Facades\DB;

class UpdateKeywordAction
{
    public function __construct(
        protected RankingSnapshotGuard $guard,
    ) {}

    /**
     * Update keyword master data. Only keys present in $attributes change.
     * Keywords are project-level, so locked historical cycles never block this.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Keyword $keyword, array $attributes): Keyword
    {
        return DB::transaction(function () use ($keyword, $attributes): Keyword {
            $normalised = KeywordAttributes::normalise($attributes);

            $keywordNormalized = $normalised['keyword_normalized'] ?? $keyword->keyword_normalized;
            $locationNormalized = $normalised['location_normalized'] ?? $keyword->location_normalized;

            if ($keywordNormalized !== $keyword->keyword_normalized || $locationNormalized !== $keyword->location_normalized) {
                KeywordAttributes::ensureUniqueWithin($keyword->project, $keywordNormalized, $locationNormalized, $keyword);
            }

            if (array_key_exists('target_page_id', $attributes)) {
                $keyword->target_page_id = $this->guard->resolveTargetPage($keyword->project, $attributes['target_page_id'])?->getKey();
            }

            $keyword->fill($normalised)->save();

            return $keyword;
        });
    }
}
