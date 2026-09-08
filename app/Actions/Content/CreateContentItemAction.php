<?php

namespace App\Actions\Content;

use App\Enums\ContentStatus;
use App\Models\ContentItem;
use App\Models\Project;
use App\Services\Content\ContentIntegrityGuard;
use Illuminate\Support\Facades\DB;

class CreateContentItemAction
{
    public function __construct(
        protected ContentIntegrityGuard $guard,
    ) {}

    /**
     * Create a content work item, project-level (no cycle) or monthly.
     *
     * @param  array<string, mixed>  $attributes  title, content_type, status, monthly_cycle_id, target_keyword_id, assigned_user_id, planned_publish_date, published_at, published_url, notes
     */
    public function handle(Project $project, array $attributes): ContentItem
    {
        return DB::transaction(function () use ($project, $attributes): ContentItem {
            $cycle = $this->guard->resolveCycle($project, $attributes['monthly_cycle_id'] ?? null);
            $this->guard->ensureCycleNotLocked($cycle, 'add content to it');

            $keyword = $this->guard->resolveKeyword($project, $attributes['target_keyword_id'] ?? null);
            $this->guard->ensureAssignable($project, $attributes['assigned_user_id'] ?? null);

            $status = $this->guard->normaliseStatus($attributes['status'] ?? ContentStatus::Idea);

            $item = new ContentItem([
                'title' => $this->guard->normaliseTitle($attributes['title'] ?? null),
                'content_type' => $this->guard->normaliseType($attributes['content_type'] ?? null),
                'status' => $status,
                'planned_publish_date' => $this->guard->normaliseDate($attributes['planned_publish_date'] ?? null),
                'notes' => $this->guard->normaliseNotes($attributes['notes'] ?? null),
            ]);

            $item->project_id = $project->getKey();
            $item->monthly_cycle_id = $cycle?->getKey();
            $item->target_keyword_id = $keyword?->getKey();
            $item->assigned_user_id = filled($attributes['assigned_user_id'] ?? null) ? (int) $attributes['assigned_user_id'] : null;

            // Publish details only mean something while published.
            $item->published_at = $status->isPublished() ? $this->guard->normaliseDateTime($attributes['published_at'] ?? null) : null;
            $item->published_url = $status->isPublished() ? $this->guard->normaliseUrl($attributes['published_url'] ?? null) : null;

            $this->guard->ensurePublishable($item);

            $item->save();

            return $item;
        });
    }
}
