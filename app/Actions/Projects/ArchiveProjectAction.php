<?php

namespace App\Actions\Projects;

use App\Models\Project;

class ArchiveProjectAction
{
    /**
     * Archive a project by soft-deleting it. The row, its client link, team
     * memberships and all future monthly history remain in the database and
     * the project can be restored. Nothing is ever hard-deleted here.
     */
    public function handle(Project $project): Project
    {
        if (! $project->trashed()) {
            $project->delete();
        }

        return $project;
    }
}
