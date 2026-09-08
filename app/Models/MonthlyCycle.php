<?php

namespace App\Models;

use App\Enums\MonthlyCycleStatus;
use App\Support\MonthlyCycles\CyclePeriod;
use Database\Factories\MonthlyCycleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Project + year + month: the reporting-period anchor for all monthly
 * operational data. Its targets are a snapshot taken at creation.
 */
class MonthlyCycle extends Model
{
    /** @use HasFactory<MonthlyCycleFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'year',
        'month',
        'status',
        'started_at',
        'locked_at',
        'locked_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'status' => MonthlyCycleStatus::class,
            'started_at' => 'datetime',
            'locked_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    /**
     * Snapshotted targets in the order they were captured.
     *
     * @return HasMany<MonthlyCycleTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(MonthlyCycleTarget::class)->orderBy('id');
    }

    /**
     * Monthly tasks attached to this cycle.
     *
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Page optimisation events reported in this cycle.
     *
     * @return HasMany<PageOptimization, $this>
     */
    public function pageOptimizations(): HasMany
    {
        return $this->hasMany(PageOptimization::class);
    }

    public function period(): CyclePeriod
    {
        return new CyclePeriod($this->year, $this->month);
    }

    /**
     * e.g. "September 2026".
     */
    public function periodLabel(): string
    {
        return $this->period()->label();
    }

    public function isLocked(): bool
    {
        return $this->status === MonthlyCycleStatus::Locked;
    }

    /**
     * @param  Builder<MonthlyCycle>  $query
     * @return Builder<MonthlyCycle>
     */
    public function scopeForPeriod(Builder $query, CyclePeriod $period): Builder
    {
        return $query->where('year', $period->year)->where('month', $period->month);
    }

    /**
     * Newest period first.
     *
     * @param  Builder<MonthlyCycle>  $query
     * @return Builder<MonthlyCycle>
     */
    public function scopeLatestPeriodFirst(Builder $query): Builder
    {
        return $query->orderByDesc('year')->orderByDesc('month');
    }
}
