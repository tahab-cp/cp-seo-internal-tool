<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Support\MonthlyCycles\CyclePeriod;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * An operational work item. Project-level when monthly_cycle_id is null,
 * otherwise attached to one monthly cycle of the same project.
 *
 * Tasks are workflow only: completing one never changes any deliverable
 * target or progress figure.
 */
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'monthly_cycle_id',
        'task_template_item_id',
        'assigned_user_id',
        'created_by',
        'title',
        'description',
        'category',
        'status',
        'priority',
        'due_date',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'due_date' => 'date',
            'completed_at' => 'datetime',
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
     * @return BelongsTo<TaskTemplateItem, $this>
     */
    public function templateItem(): BelongsTo
    {
        return $this->belongsTo(TaskTemplateItem::class, 'task_template_item_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isProjectLevel(): bool
    {
        return $this->monthly_cycle_id === null;
    }

    /**
     * Monthly tasks inherit their cycle's lock; project-level tasks never lock.
     */
    public function isLocked(): bool
    {
        if ($this->isProjectLevel()) {
            return false;
        }

        return $this->monthlyCycle?->isLocked() ?? false;
    }

    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && $this->status->isOpen()
            && $this->due_date->lt(Carbon::today());
    }

    /**
     * Single source of truth for task visibility: a task is visible exactly
     * when its project is visible (Project::scopeAccessibleBy).
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeAccessibleBy(Builder $query, ?User $user): Builder
    {
        return $query->whereHas('project', fn (Builder $project) => $project->accessibleBy($user));
    }

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeAssignedTo(Builder $query, User $user): Builder
    {
        return $query->where('assigned_user_id', $user->getKey());
    }

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', TaskStatus::openValues());
    }

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', TaskStatus::Completed->value);
    }

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()->whereDate('due_date', '<', Carbon::today());
    }

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeDueToday(Builder $query): Builder
    {
        return $query->open()->whereDate('due_date', Carbon::today());
    }

    /**
     * Open tasks due within the current calendar week (Monday to Sunday).
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeDueThisWeek(Builder $query): Builder
    {
        return $query->open()->whereBetween('due_date', [
            Carbon::today()->startOfWeek()->toDateString(),
            Carbon::today()->endOfWeek()->toDateString(),
        ]);
    }

    /**
     * Tasks attached to the cycle for the given period.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeForPeriod(Builder $query, CyclePeriod $period): Builder
    {
        return $query->whereHas('monthlyCycle', fn (Builder $cycle) => $cycle->forPeriod($period));
    }
}
