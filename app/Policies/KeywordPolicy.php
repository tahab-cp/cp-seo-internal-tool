<?php

namespace App\Policies;

use App\Models\Keyword;
use App\Models\Project;
use App\Models\User;

/**
 * Keyword access follows project access. Keywords are project master
 * data, so locked historical cycles never make them immutable. Nobody
 * deletes keywords through normal workflows (archive them instead).
 */
class KeywordPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny', Project::class);
    }

    public function view(User $user, Keyword $keyword): bool
    {
        return $user->can('view', $keyword->project);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Keyword $keyword): bool
    {
        return $this->view($user, $keyword);
    }

    public function setStatus(User $user, Keyword $keyword): bool
    {
        return $this->view($user, $keyword);
    }

    public function delete(User $user, Keyword $keyword): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Keyword $keyword): bool
    {
        return false;
    }

    public function forceDelete(User $user, Keyword $keyword): bool
    {
        return false;
    }
}
