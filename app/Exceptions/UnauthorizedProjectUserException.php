<?php

namespace App\Exceptions;

use App\Models\Project;
use App\Models\User;
use InvalidArgumentException;

/**
 * Thrown when a record would reference a user who cannot access its project.
 */
class UnauthorizedProjectUserException extends InvalidArgumentException
{
    public static function for(User $user, Project $project, string $role): self
    {
        return new self(sprintf(
            'User [%s] cannot be recorded as %s on project "%s" because they cannot access it.',
            $user->email,
            $role,
            $project->name,
        ));
    }
}
