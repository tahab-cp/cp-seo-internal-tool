<?php

namespace App\Policies;

use App\Models\ImportBatch;
use App\Models\Project;
use App\Models\User;

/**
 * Import history follows project visibility: anyone who may see a project
 * may import into it and review its imports. History is never deleted.
 */
class ImportBatchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny', Project::class);
    }

    public function view(User $user, ImportBatch $batch): bool
    {
        return $user->can('view', $batch->project);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, ImportBatch $batch): bool
    {
        return false;
    }

    public function delete(User $user, ImportBatch $batch): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, ImportBatch $batch): bool
    {
        return false;
    }

    public function forceDelete(User $user, ImportBatch $batch): bool
    {
        return false;
    }
}
