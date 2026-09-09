<?php

namespace App\Support\Imports;

/**
 * One application field an import type accepts from a CSV column.
 */
final readonly class ImportField
{
    /**
     * @param  list<string>  $aliases  extra header spellings auto-suggested for this field
     */
    public function __construct(
        public string $key,
        public string $label,
        public bool $required = false,
        public array $aliases = [],
        public ?string $hint = null,
    ) {}

    public static function required(string $key, string $label, array $aliases = [], ?string $hint = null): self
    {
        return new self($key, $label, true, $aliases, $hint);
    }

    public static function optional(string $key, string $label, array $aliases = [], ?string $hint = null): self
    {
        return new self($key, $label, false, $aliases, $hint);
    }
}
