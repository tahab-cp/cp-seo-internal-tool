<?php

namespace App\Exceptions;

use App\Models\Project;
use App\Models\User;
use InvalidArgumentException;

/**
 * Thrown when a task would be assigned to a user who cannot access its project.
 */
class TaskAssignmentException extends InvalidArgumentException
{
    public static function cannotAccessProject(User $user, Project $project): self
    {
        return new self(sprintf(
            'User [%s] cannot be assigned tasks on project "%s" because they cannot access it.',
            $user->email,
            $project->name,
        ));
    }
}
