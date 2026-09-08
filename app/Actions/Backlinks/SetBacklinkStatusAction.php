<?php

namespace App\Actions\Backlinks;

use App\Enums\BacklinkStatus;
use App\Models\Backlink;
use App\Services\Backlinks\BacklinkGuard;

class SetBacklinkStatusAction
{
    public function __construct(
        protected BacklinkGuard $guard,
    ) {}

    /**
     * Change lifecycle status (planned → submitted → live → removed …).
     * "Removed" is the normal way to retire a link; progress is derived, so
     * the change is reflected immediately. Refused in locked cycles.
     */
    public function handle(Backlink $backlink, BacklinkStatus|string $status): Backlink
    {
        $this->guard->ensureBacklinkNotLocked($backlink, 'change backlink status in it');

        $backlink->status = $this->guard->normaliseStatus($status);
        $backlink->save();

        return $backlink;
    }
}
