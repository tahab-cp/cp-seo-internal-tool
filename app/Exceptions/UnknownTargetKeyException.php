<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a project override references a target key that the
 * project's package does not define (or the project has no package).
 */
class UnknownTargetKeyException extends InvalidArgumentException
{
    /**
     * @param  list<string>  $keys
     */
    public static function for(array $keys, ?string $packageName): self
    {
        $scope = $packageName === null
            ? 'the project has no package'
            : "package \"{$packageName}\" does not define them";

        return new self(sprintf(
            'Target override(s) [%s] cannot be applied: %s.',
            implode(', ', $keys),
            $scope,
        ));
    }
}
