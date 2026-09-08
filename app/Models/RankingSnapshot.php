<?php

namespace App\Models;

use App\Enums\RankingSource;
use Database\Factories\RankingSnapshotFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One historical ranking observation: where a keyword ranked at a moment,
 * reported in a monthly cycle. A NULL position means "not ranking".
 *
 * Identified by keyword + checked_at + source; recording the same
 * observation again updates it rather than duplicating it.
 */
class RankingSnapshot extends Model
{
    /** @use HasFactory<RankingSnapshotFactory> */
    use HasFactory;

    public const NOT_RANKING_LABEL = 'Not Ranking';

    /**
     * Observations carry only created_at (see docs/database-design.md).
     */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'keyword_id',
        'monthly_cycle_id',
        'checked_at',
        'position',
        'ranking_url',
        'source',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'position' => 'integer',
            'source' => RankingSource::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Keyword, $this>
     */
    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class)->withTrashed();
    }

    /**
     * @return BelongsTo<MonthlyCycle, $this>
     */
    public function monthlyCycle(): BelongsTo
    {
        return $this->belongsTo(MonthlyCycle::class);
    }

    public function isRanking(): bool
    {
        return $this->position !== null;
    }

    public function positionLabel(): string
    {
        return $this->position === null ? self::NOT_RANKING_LABEL : (string) $this->position;
    }

    public function isLocked(): bool
    {
        return $this->monthlyCycle?->isLocked() ?? false;
    }

    /**
     * Visible exactly when the keyword's project is visible.
     *
     * @param  Builder<RankingSnapshot>  $query
     * @return Builder<RankingSnapshot>
     */
    public function scopeAccessibleBy(Builder $query, ?User $user): Builder
    {
        return $query->whereHas('keyword', fn (Builder $keyword) => $keyword->accessibleBy($user));
    }
}
