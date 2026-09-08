<?php

namespace App\Models;

use App\Enums\PageStatus;
use Database\Factories\PageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Project-level master data: one URL on the project's website.
 *
 * Operational removal sets status = removed; soft deletes exist only for
 * exceptional administrative cleanup. Optimisation history always survives.
 */
class Page extends Model
{
    /** @use HasFactory<PageFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'url',
        'path',
        'title',
        'page_type',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PageStatus::class,
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
     * Historical optimisation events, newest first.
     *
     * @return HasMany<PageOptimization, $this>
     */
    public function optimizations(): HasMany
    {
        return $this->hasMany(PageOptimization::class)
            ->orderByDesc('optimized_at')
            ->orderByDesc('id');
    }

    /**
     * Keywords targeting this page.
     *
     * @return HasMany<Keyword, $this>
     */
    public function keywords(): HasMany
    {
        return $this->hasMany(Keyword::class, 'target_page_id');
    }

    public function isRemoved(): bool
    {
        return $this->status === PageStatus::Removed;
    }

    public function displayName(): string
    {
        return filled($this->title) ? $this->title : ($this->path ?? $this->url);
    }

    /**
     * Single source of truth for page visibility: a page is visible exactly
     * when its project is visible (Project::scopeAccessibleBy).
     *
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    public function scopeAccessibleBy(Builder $query, ?User $user): Builder
    {
        return $query->whereHas('project', fn (Builder $project) => $project->accessibleBy($user));
    }

    /**
     * Pages that may receive new optimisation work.
     *
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    public function scopeOptimisable(Builder $query): Builder
    {
        return $query->where('status', '!=', PageStatus::Removed->value);
    }
}
