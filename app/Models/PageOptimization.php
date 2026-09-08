<?php

namespace App\Models;

use Database\Factories\PageOptimizationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One historical SEO work event on a page within a reporting month.
 * Monthly operational data: immutable once its cycle is locked.
 */
class PageOptimization extends Model
{
    /** @use HasFactory<PageOptimizationFactory> */
    use HasFactory;

    /**
     * The change flags; at least one must be true for a record to count.
     *
     * @var array<string, string>
     */
    public const CHANGE_FLAGS = [
        'meta_title_updated' => 'Meta title',
        'meta_description_updated' => 'Meta description',
        'content_updated' => 'Content',
        'internal_links_updated' => 'Internal links',
        'schema_updated' => 'Schema',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'page_id',
        'monthly_cycle_id',
        'user_id',
        'optimized_at',
        'meta_title_updated',
        'meta_description_updated',
        'content_updated',
        'internal_links_updated',
        'schema_updated',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'optimized_at' => 'datetime',
            'meta_title_updated' => 'boolean',
            'meta_description_updated' => 'boolean',
            'content_updated' => 'boolean',
            'internal_links_updated' => 'boolean',
            'schema_updated' => 'boolean',
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
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class)->withTrashed();
    }

    /**
     * @return BelongsTo<MonthlyCycle, $this>
     */
    public function monthlyCycle(): BelongsTo
    {
        return $this->belongsTo(MonthlyCycle::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasAnyChange(): bool
    {
        foreach (array_keys(self::CHANGE_FLAGS) as $flag) {
            if ($this->{$flag}) {
                return true;
            }
        }

        return false;
    }

    /**
     * Human labels of the flags that are true, in canonical order.
     *
     * @return list<string>
     */
    public function changeLabels(): array
    {
        return array_values(array_filter(
            self::CHANGE_FLAGS,
            fn (string $flag): bool => (bool) $this->{$flag},
            ARRAY_FILTER_USE_KEY,
        ));
    }

    public function isLocked(): bool
    {
        return $this->monthlyCycle?->isLocked() ?? false;
    }

    /**
     * @param  Builder<PageOptimization>  $query
     * @return Builder<PageOptimization>
     */
    public function scopeAccessibleBy(Builder $query, ?User $user): Builder
    {
        return $query->whereHas('project', fn (Builder $project) => $project->accessibleBy($user));
    }
}
