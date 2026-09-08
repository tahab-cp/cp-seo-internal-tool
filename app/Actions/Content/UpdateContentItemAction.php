<?php

namespace App\Actions\Content;

use App\Models\ContentItem;
use App\Services\Content\ContentIntegrityGuard;
use Illuminate\Support\Facades\DB;

class UpdateContentItemAction
{
    public function __construct(
        protected ContentIntegrityGuard $guard,
    ) {}

    /**
     * Edit a content item. Refused while its cycle is locked; moving it
     * requires a same-project, unlocked destination. Only keys present in
     * $attributes change; the project never changes. Leaving the published
     * status clears the publish details (the item is no longer represented
     * as published).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function handle(ContentItem $item, array $attributes): ContentItem
    {
        return DB::transaction(function () use ($item, $attributes): ContentItem {
            $this->guard->ensureItemNotLocked($item, 'edit content in it');

            $project = $item->project;

            if (array_key_exists('monthly_cycle_id', $attributes)) {
                $cycle = $this->guard->resolveCycle($project, $attributes['monthly_cycle_id']);
                $this->guard->ensureCycleNotLocked($cycle, 'move content into it');
                $item->monthly_cycle_id = $cycle?->getKey();
            }

            if (array_key_exists('target_keyword_id', $attributes)) {
                $item->target_keyword_id = $this->guard->resolveKeyword($project, $attributes['target_keyword_id'])?->getKey();
            }

            if (array_key_exists('assigned_user_id', $attributes)) {
                $newAssignee = filled($attributes['assigned_user_id']) ? (int) $attributes['assigned_user_id'] : null;

                // Only a *change* of assignee is validated; an existing (possibly
                // now-inactive) assignee is historical and may be kept.
                if ($newAssignee !== $item->assigned_user_id) {
                    $this->guard->ensureAssignable($project, $newAssignee);
                }

                $item->assigned_user_id = $newAssignee;
            }

            if (array_key_exists('title', $attributes)) {
                $item->title = $this->guard->normaliseTitle($attributes['title']);
            }

            if (array_key_exists('content_type', $attributes)) {
                $item->content_type = $this->guard->normaliseType($attributes['content_type']);
            }

            if (array_key_exists('status', $attributes)) {
                $item->status = $this->guard->normaliseStatus($attributes['status']);
            }

            if (array_key_exists('planned_publish_date', $attributes)) {
                $item->planned_publish_date = $this->guard->normaliseDate($attributes['planned_publish_date']);
            }

            if (array_key_exists('published_at', $attributes)) {
                $item->published_at = $this->guard->normaliseDateTime($attributes['published_at']);
            }

            if (array_key_exists('published_url', $attributes)) {
                $item->published_url = $this->guard->normaliseUrl($attributes['published_url']);
            }

            if (array_key_exists('notes', $attributes)) {
                $item->notes = $this->guard->normaliseNotes($attributes['notes']);
            }

            if (! $item->status->isPublished()) {
                $item->published_at = null;
                $item->published_url = null;
            }

            $this->guard->ensurePublishable($item);

            $item->save();

            return $item;
        });
    }
}
