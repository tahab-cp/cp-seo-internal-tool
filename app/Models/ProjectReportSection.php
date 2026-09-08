<?php

namespace App\Models;

use App\Enums\ReportSectionKey;
use Database\Factories\ProjectReportSectionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A project's report template row for one known section. Governs FUTURE
 * reports only; every monthly report snapshots these rows at creation.
 */
class ProjectReportSection extends Model
{
    /** @use HasFactory<ProjectReportSectionFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'section_key',
        'title',
        'is_enabled',
        'is_required',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'section_key' => ReportSectionKey::class,
            'is_enabled' => 'boolean',
            'is_required' => 'boolean',
            'sort_order' => 'integer',
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
     * @param  Builder<ProjectReportSection>  $query
     * @return Builder<ProjectReportSection>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
