<?php

namespace App\Models;

use App\Enums\KeywordIntent;
use App\Enums\KeywordRole;
use App\Enums\KeywordStatus;
use Database\Factories\KeywordFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Project-level master data: one tracked search term (optionally for a
 * location). Rankings are recorded as RankingSnapshots; nothing about
 * position or movement is stored here.
 */
class Keyword extends Model
{
    /** @use HasFactory<KeywordFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'keyword',
        'keyword_normalized',
        'target_page_id',
        'keyword_role',
        'search_volume',
        'keyword_difficulty',
        'search_intent',
        'location',
        'location_normalized',
        'is_branded',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'keyword_role' => KeywordRole::class,
            'search_intent' => KeywordIntent::class,
            'status' => KeywordStatus::class,
            'search_volume' => 'integer',
            'keyword_difficulty' => 'integer',
            'is_branded' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The page this keyword should rank for, if any (kept even when the
     * page is later removed or soft-deleted).
     *
     * @return BelongsTo<Page, $this>
     */
    public function targetPage(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'target_page_id')->withTrashed();
    }

    /**
     * Ranking history, newest observation first.
     *
     * @return HasMany<RankingSnapshot, $this>
     */
    public function rankingSnapshots(): HasMany
    {
        return $this->hasMany(RankingSnapshot::class)
            ->orderByDesc('checked_at')
            ->orderByDesc('id');
    }

    /**
     * The most recent observation across all history (derived, never stored).
     *
     * @return HasOne<RankingSnapshot, $this>
     */
    public function latestSnapshot(): HasOne
    {
        return $this->hasOne(RankingSnapshot::class)->latestOfMany('checked_at');
    }

    public function isArchived(): bool
    {
        return $this->status === KeywordStatus::Archived;
    }

    public function displayLocation(): string
    {
        return $this->location ?? 'Any location';
    }

    /**
     * Single source of truth for keyword visibility: a keyword is visible
     * exactly when its project is visible (Project::scopeAccessibleBy).
     *
     * @param  Builder<Keyword>  $query
     * @return Builder<Keyword>
     */
    public function scopeAccessibleBy(Builder $query, ?User $user): Builder
    {
        return $query->whereHas('project', fn (Builder $project) => $project->accessibleBy($user));
    }

    /**
     * @param  Builder<Keyword>  $query
     * @return Builder<Keyword>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', KeywordStatus::Active->value);
    }
}
