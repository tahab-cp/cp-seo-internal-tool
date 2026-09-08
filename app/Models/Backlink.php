<?php

namespace App\Models;

use App\Enums\BacklinkStatus;
use App\Enums\BacklinkType;
use Database\Factories\BacklinkFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One link-building record in a reporting month. Monthly operational data:
 * immutable once its cycle is locked. Only live links count toward the
 * backlinks target; live guest posts count toward guest_posts as well.
 */
class Backlink extends Model
{
    /** @use HasFactory<BacklinkFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'monthly_cycle_id',
        'created_by',
        'published_date',
        'published_url',
        'anchor_text',
        'target_url',
        'type',
        'status',
        'domain_authority',
        'domain_rating',
        'spam_score',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_date' => 'date',
            'type' => BacklinkType::class,
            'status' => BacklinkStatus::class,
            'domain_authority' => 'integer',
            'domain_rating' => 'integer',
            'spam_score' => 'integer',
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
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isLive(): bool
    {
        return $this->status->counts();
    }

    public function isGuestPost(): bool
    {
        return $this->type === BacklinkType::GuestPost;
    }

    public function isLocked(): bool
    {
        return $this->monthlyCycle?->isLocked() ?? false;
    }

    /**
     * Visible exactly when the project is visible (Project::scopeAccessibleBy).
     *
     * @param  Builder<Backlink>  $query
     * @return Builder<Backlink>
     */
    public function scopeAccessibleBy(Builder $query, ?User $user): Builder
    {
        return $query->whereHas('project', fn (Builder $project) => $project->accessibleBy($user));
    }

    /**
     * @param  Builder<Backlink>  $query
     * @return Builder<Backlink>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', BacklinkStatus::Live->value);
    }

    /**
     * @param  Builder<Backlink>  $query
     * @return Builder<Backlink>
     */
    public function scopeGuestPosts(Builder $query): Builder
    {
        return $query->where('type', BacklinkType::GuestPost->value);
    }
}
