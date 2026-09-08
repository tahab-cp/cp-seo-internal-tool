<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Enums\ContentType;
use Database\Factories\ContentItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One content deliverable / work item (operations only, never the body).
 * Project-level when monthly_cycle_id is null; monthly operational data
 * (immutable when locked) once attached to a cycle. Published blogs in a
 * cycle feed the blogs target; that count is always derived.
 */
class ContentItem extends Model
{
    /** @use HasFactory<ContentItemFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'monthly_cycle_id',
        'assigned_user_id',
        'target_keyword_id',
        'title',
        'content_type',
        'status',
        'planned_publish_date',
        'published_at',
        'published_url',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'content_type' => ContentType::class,
            'status' => ContentStatus::class,
            'planned_publish_date' => 'date',
            'published_at' => 'datetime',
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
     * @return BelongsTo<MonthlyCycle, $this>
     */
    public function monthlyCycle(): BelongsTo
    {
        return $this->belongsTo(MonthlyCycle::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * Kept even when the keyword is later archived or soft-deleted.
     *
     * @return BelongsTo<Keyword, $this>
     */
    public function targetKeyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class, 'target_keyword_id')->withTrashed();
    }

    public function isProjectLevel(): bool
    {
        return $this->monthly_cycle_id === null;
    }

    public function isPublished(): bool
    {
        return $this->status->isPublished();
    }

    /**
     * Monthly items inherit their cycle's lock; project-level items never lock.
     */
    public function isLocked(): bool
    {
        if ($this->isProjectLevel()) {
            return false;
        }

        return $this->monthlyCycle?->isLocked() ?? false;
    }

    /**
     * Visible exactly when the project is visible (Project::scopeAccessibleBy).
     *
     * @param  Builder<ContentItem>  $query
     * @return Builder<ContentItem>
     */
    public function scopeAccessibleBy(Builder $query, ?User $user): Builder
    {
        return $query->whereHas('project', fn (Builder $project) => $project->accessibleBy($user));
    }

    /**
     * @param  Builder<ContentItem>  $query
     * @return Builder<ContentItem>
     */
    public function scopePublishedBlogs(Builder $query): Builder
    {
        return $query
            ->where('content_type', ContentType::Blog->value)
            ->where('status', ContentStatus::Published->value);
    }

    /**
     * @param  Builder<ContentItem>  $query
     * @return Builder<ContentItem>
     */
    public function scopeUnscheduled(Builder $query): Builder
    {
        return $query->whereNull('monthly_cycle_id');
    }
}
