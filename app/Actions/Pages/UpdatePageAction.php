<?php

namespace App\Actions\Pages;

use App\Models\Page;
use Illuminate\Support\Facades\DB;

class UpdatePageAction
{
    /**
     * Update page master data. Only keys present in $attributes change.
     * Pages are project-level, so locked historical cycles never block this.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Page $page, array $attributes): Page
    {
        return DB::transaction(function () use ($page, $attributes): Page {
            $attributes = PageAttributes::normalise($attributes);

            if (isset($attributes['url']) && $attributes['url'] !== $page->url) {
                PageAttributes::ensureUrlUniqueWithin($page->project, $attributes['url'], $page);
            }

            $page->fill($attributes)->save();

            return $page;
        });
    }
}
