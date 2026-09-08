<?php

namespace App\Actions\Projects;

use App\Exceptions\InactivePackageAssignmentException;
use App\Models\Package;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

class ChangeProjectPackageAction
{
    /**
     * Assign, replace or remove the project's package.
     *
     * - Keeping the current package (even if it is now inactive) is a no-op.
     * - A *new* package must exist and be active.
     * - Whenever package_id changes, every project target override is
     *   cleared: overrides belong to the old contractual configuration and
     *   must be configured intentionally for the new one.
     */
    public function handle(Project $project, int|string|null $packageId): Project
    {
        $packageId = $packageId === null || $packageId === '' ? null : (int) $packageId;

        if ($packageId === (int) $project->package_id || ($packageId === null && $project->package_id === null)) {
            return $project;
        }

        if ($packageId !== null && ! Package::query()->active()->whereKey($packageId)->exists()) {
            throw InactivePackageAssignmentException::for($packageId);
        }

        DB::transaction(function () use ($project, $packageId): void {
            $project->forceFill(['package_id' => $packageId])->save();

            $project->targetOverrides()->delete();
        });

        $project->unsetRelation('package');
        $project->unsetRelation('targetOverrides');

        return $project;
    }
}
