<?php

namespace App\Actions\Pages;

use App\Enums\PageStatus;
use App\Models\Page;

class SetPageStatusAction
{
    /**
     * Change a page's lifecycle status. "Removed" is the normal way to
     * retire a page: it keeps the row and all optimisation history.
     */
    public function handle(Page $page, PageStatus|string $status): Page
    {
        $page->status = $status instanceof PageStatus ? $status : PageStatus::from($status);
        $page->save();

        return $page;
    }
}
