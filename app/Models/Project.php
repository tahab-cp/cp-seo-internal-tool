<?php

namespace App\Models;

use App\Enums\Permission;
use App\Enums\ProjectStatus;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One SEO engagement / website belonging to exactly one Client.
 *
 * All SEO operational data (added in later milestones) hangs off the
 * Project, never the Client. Archiving a project soft-deletes it, which
 * keeps its client link, team memberships and future history intact.
 */
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'client_id',
        'package_id',
        'name',
        'website_url',
        'target_location',
        'status',
        'start_date',
        'end_date',
        'primary_seo_user_id',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * The package currently assigned, active or not. An inactive package
     * remains assigned and still resolves its targets.
     *
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /**
     * Project-specific values for keys defined by the assigned package.
     *
     * @return HasMany<ProjectTargetOverride, $this>
     */
    public function targetOverrides(): HasMany
    {
        return $this->hasMany(ProjectTargetOverride::class);
    }

    /**
     * Reporting periods, one per year/month.
     *
     * @return HasMany<MonthlyCycle, $this>
     */
    public function monthlyCycles(): HasMany
    {
        return $this->hasMany(MonthlyCycle::class);
    }

    /**
     * Operational tasks, both project-level and monthly.
     *
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Website pages (project master data).
     *
     * @return HasMany<Page, $this>
     */
    public function pages(): HasMany
    {
        return $this->hasMany(Page::class);
    }

    /**
     * @return HasMany<PageOptimization, $this>
     */
    public function pageOptimizations(): HasMany
    {
        return $this->hasMany(PageOptimization::class);
    }

    /**
     * Tracked keywords (project master data).
     *
     * @return HasMany<Keyword, $this>
     */
    public function keywords(): HasMany
    {
        return $this->hasMany(Keyword::class);
    }

    /**
     * Link-building records across all months.
     *
     * @return HasMany<Backlink, $this>
     */
    public function backlinks(): HasMany
    {
        return $this->hasMany(Backlink::class);
    }

    /**
     * Content work items, project-level and monthly.
     *
     * @return HasMany<ContentItem, $this>
     */
    public function contentItems(): HasMany
    {
        return $this->hasMany(ContentItem::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function primarySeoUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'primary_seo_user_id');
    }

    /**
     * Additional team members. The primary SEO owner is never stored here.
     *
     * @return BelongsToMany<User, $this>
     */
    public function teamMembers(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('project_role')
            ->withTimestamps();
    }

    public function isPrimarySeoUser(User $user): bool
    {
        return $this->primary_seo_user_id !== null
            && (int) $this->primary_seo_user_id === (int) $user->getKey();
    }

    public function hasTeamMember(User $user): bool
    {
        return $this->teamMembers()->whereKey($user->getKey())->exists();
    }

    /**
     * The visibility rule for users without global project access.
     */
    public function isAccessibleBy(User $user): bool
    {
        return $this->isPrimarySeoUser($user) || $this->hasTeamMember($user);
    }

    /**
     * Single source of truth for which projects a user may see. Applied by
     * ProjectResource::getEloquentQuery() so lists, record route binding,
     * global search and relation managers can never leak a project.
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public function scopeAccessibleBy(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasPermission(Permission::ViewAllProjects)) {
            return $query;
        }

        if (! $user->hasPermission(Permission::ViewAssignedProjects)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $query) use ($user): void {
            $query
                ->where('primary_seo_user_id', $user->getKey())
                ->orWhereHas('teamMembers', fn (Builder $members) => $members->whereKey($user->getKey()));
        });
    }
}
