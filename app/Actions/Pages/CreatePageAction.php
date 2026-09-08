<?php

namespace App\Actions\Pages;

use App\Enums\PageStatus;
use App\Models\Page;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

class CreatePageAction
{
    /**
     * Add a page to a project's master data.
     *
     * @param  array<string, mixed>  $attributes  url, path, title, page_type, status
     */
    public function handle(Project $project, array $attributes): Page
    {
        return DB::transaction(function () use ($project, $attributes): Page {
            $attributes = PageAttributes::normalise($attributes);

            PageAttributes::ensureUrlUniqueWithin($project, $attributes['url']);

            $page = new Page($attributes + ['status' => PageStatus::Active]);
            $page->project_id = $project->getKey();
            $page->save();

            return $page;
        });
    }
}
