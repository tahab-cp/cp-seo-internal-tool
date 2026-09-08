<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a workflow tries to assign a package that is inactive or
 * does not exist. Projects already on a package that later became inactive
 * keep it; only *new* assignments are rejected.
 */
class InactivePackageAssignmentException extends InvalidArgumentException
{
    public static function for(int $packageId): self
    {
        return new self("Package [{$packageId}] is inactive or does not exist and cannot be assigned to a project.");
    }
}
