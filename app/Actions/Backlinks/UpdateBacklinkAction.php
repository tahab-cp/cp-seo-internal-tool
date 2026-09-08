<?php

namespace App\Actions\Backlinks;

use App\Models\Backlink;
use App\Services\Backlinks\BacklinkGuard;
use Illuminate\Support\Facades\DB;

class UpdateBacklinkAction
{
    public function __construct(
        protected BacklinkGuard $guard,
    ) {}

    /**
     * Edit a backlink. Refused entirely while its cycle is locked; moving it
     * requires a same-project, unlocked destination cycle. Only keys present
     * in $attributes change; project and creator never change.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Backlink $backlink, array $attributes): Backlink
    {
        return DB::transaction(function () use ($backlink, $attributes): Backlink {
            $this->guard->ensureBacklinkNotLocked($backlink, 'edit backlinks in it');

            if (array_key_exists('monthly_cycle_id', $attributes)) {
                $cycle = $this->guard->resolveCycle($backlink->project, $attributes['monthly_cycle_id']);
                $this->guard->ensureCycleNotLocked($cycle, 'move backlinks into it');
                $backlink->monthly_cycle_id = $cycle->getKey();
            }

            if (array_key_exists('published_url', $attributes)) {
                $backlink->published_url = $this->guard->normaliseUrl($attributes['published_url'], true, 'published URL');
            }

            if (array_key_exists('target_url', $attributes)) {
                $backlink->target_url = $this->guard->normaliseUrl($attributes['target_url'], false, 'target URL');
            }

            if (array_key_exists('anchor_text', $attributes)) {
                $backlink->anchor_text = $this->guard->normaliseText($attributes['anchor_text'], 255, 'anchor text');
            }

            if (array_key_exists('type', $attributes)) {
                $backlink->type = $this->guard->normaliseType($attributes['type']);
            }

            if (array_key_exists('status', $attributes)) {
                $backlink->status = $this->guard->normaliseStatus($attributes['status']);
            }

            if (array_key_exists('published_date', $attributes)) {
                $backlink->published_date = $this->guard->normaliseDate($attributes['published_date']);
            }

            foreach (['domain_authority' => 'domain authority', 'domain_rating' => 'domain rating', 'spam_score' => 'spam score'] as $field => $label) {
                if (array_key_exists($field, $attributes)) {
                    $backlink->{$field} = $this->guard->normaliseMetric($attributes[$field], $label);
                }
            }

            if (array_key_exists('notes', $attributes)) {
                $backlink->notes = $this->guard->normaliseText($attributes['notes'], 5000, 'notes');
            }

            $backlink->save();

            return $backlink;
        });
    }
}
