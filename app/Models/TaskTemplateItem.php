<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One checklist line on a task template. Generated tasks reference the
 * item that produced them so generation stays idempotent.
 */
class TaskTemplateItem extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'task_template_id',
        'title',
        'description',
        'category',
        'phase',
        'default_due_days',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_due_days' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<TaskTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(TaskTemplate::class, 'task_template_id');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function generatedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'task_template_item_id');
    }
}
