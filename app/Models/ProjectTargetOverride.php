<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A project-specific value for one of its package's target keys.
 * Overrides never introduce keys the package does not define.
 */
class ProjectTargetOverride extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'target_key',
        'label',
        'target_value',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_value' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
