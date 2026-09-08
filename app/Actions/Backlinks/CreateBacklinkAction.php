<?php

namespace App\Actions\Backlinks;

use App\Enums\BacklinkStatus;
use App\Models\Backlink;
use App\Models\Project;
use App\Models\User;
use App\Services\Backlinks\BacklinkGuard;
use Illuminate\Support\Facades\DB;

class CreateBacklinkAction
{
    public function __construct(
        protected BacklinkGuard $guard,
    ) {}

    /**
     * Record a backlink in a reporting month. The creator is the acting
     * user (never an arbitrary field) and must be active with project access.
     *
     * @param  array<string, mixed>  $attributes  monthly_cycle_id, published_url, anchor_text, target_url, type, status, published_date, domain_authority, domain_rating, spam_score, notes
     */
    public function handle(Project $project, array $attributes, User $creator): Backlink
    {
        return DB::transaction(function () use ($project, $attributes, $creator): Backlink {
            $cycle = $this->guard->resolveCycle($project, $attributes['monthly_cycle_id'] ?? null);
            $this->guard->ensureCycleNotLocked($cycle, 'add backlinks to it');
            $this->guard->ensureCreator($project, $creator);

            $backlink = new Backlink([
                'published_url' => $this->guard->normaliseUrl($attributes['published_url'] ?? null, true, 'published URL'),
                'target_url' => $this->guard->normaliseUrl($attributes['target_url'] ?? null, false, 'target URL'),
                'anchor_text' => $this->guard->normaliseText($attributes['anchor_text'] ?? null, 255, 'anchor text'),
                'type' => $this->guard->normaliseType($attributes['type'] ?? null),
                'status' => $this->guard->normaliseStatus($attributes['status'] ?? BacklinkStatus::Planned),
                'published_date' => $this->guard->normaliseDate($attributes['published_date'] ?? null),
                'domain_authority' => $this->guard->normaliseMetric($attributes['domain_authority'] ?? null, 'domain authority'),
                'domain_rating' => $this->guard->normaliseMetric($attributes['domain_rating'] ?? null, 'domain rating'),
                'spam_score' => $this->guard->normaliseMetric($attributes['spam_score'] ?? null, 'spam score'),
                'notes' => $this->guard->normaliseText($attributes['notes'] ?? null, 5000, 'notes'),
            ]);

            $backlink->project_id = $project->getKey();
            $backlink->monthly_cycle_id = $cycle->getKey();
            $backlink->created_by = $creator->getKey();
            $backlink->save();

            return $backlink;
        });
    }
}
