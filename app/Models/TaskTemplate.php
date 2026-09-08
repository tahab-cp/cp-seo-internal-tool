<?php

namespace App\Models;

use Database\Factories\TaskTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable onboarding checklist. Deactivated rather than deleted;
 * inactive templates stay available historically but cannot be used for
 * new onboarding generation.
 */
class TaskTemplate extends Model
{
    /** @use HasFactory<TaskTemplateFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Items in their deterministic display order.
     *
     * @return HasMany<TaskTemplateItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(TaskTemplateItem::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * @param  Builder<TaskTemplate>  $query
     * @return Builder<TaskTemplate>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
