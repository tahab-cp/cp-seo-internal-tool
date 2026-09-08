<?php

namespace App\Exceptions;

use App\Models\Project;
use InvalidArgumentException;

/**
 * Thrown when a keyword/location pair already exists in a project after
 * normalisation (case and whitespace are ignored).
 */
class DuplicateKeywordException extends InvalidArgumentException
{
    public static function for(Project $project, string $keyword, ?string $location): self
    {
        return new self(sprintf(
            'Keyword "%s" (%s) is already tracked in project "%s".',
            trim($keyword),
            $location === null || trim($location) === '' ? 'any location' : trim($location),
            $project->name,
        ));
    }
}
