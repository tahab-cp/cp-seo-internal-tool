<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a workflow tries to assign a user who is not active (or does
 * not exist) to a role that must be held by an active user.
 */
class InactiveUserAssignmentException extends InvalidArgumentException
{
    /**
     * @param  list<int>  $userIds
     */
    public static function for(string $role, array $userIds): self
    {
        return new self(sprintf(
            'Only active users can be assigned as %s. Inactive or unknown user id(s): %s.',
            $role,
            implode(', ', $userIds),
        ));
    }
}
