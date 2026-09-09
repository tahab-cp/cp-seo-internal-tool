<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Wraps a domain exception raised while writing one CSV row during the
 * final import, so the failure can be attributed to that row after the
 * batch has been rolled back.
 */
class ImportRowException extends RuntimeException
{
    public function __construct(
        public readonly int $rowNumber,
        Throwable $previous,
    ) {
        parent::__construct($previous->getMessage(), 0, $previous);
    }

    public static function forRow(int $rowNumber, Throwable $previous): self
    {
        return new self($rowNumber, $previous);
    }
}
