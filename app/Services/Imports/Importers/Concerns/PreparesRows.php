<?php

namespace App\Services\Imports\Importers\Concerns;

use App\Services\Imports\CsvValueNormalizer;
use App\Support\Imports\ImportField;
use App\Support\Imports\PreparedRow;
use Closure;
use Throwable;

/**
 * Shared helpers for importers: blank detection, required-field checks and
 * running a domain normaliser on one field so its own message becomes the
 * row error (no second copy of the rule).
 */
trait PreparesRows
{
    protected function normalizer(): CsvValueNormalizer
    {
        return app(CsvValueNormalizer::class);
    }

    protected function blank(array $values, string $key): bool
    {
        return $this->normalizer()->isBlank($values[$key] ?? null);
    }

    /**
     * Adds an error for every required field that is blank.
     *
     * @param  list<ImportField>  $fields
     */
    protected function requireFields(PreparedRow $row, array $values, array $fields): bool
    {
        $ok = true;

        foreach ($fields as $field) {
            if ($field->required && $this->blank($values, $field->key)) {
                $row->addError($field->key, "{$field->label} is required.");
                $ok = false;
            }
        }

        return $ok;
    }

    /**
     * Runs a normaliser for one field; a domain InvalidArgumentException
     * becomes the row error for that field. Returns null on failure.
     */
    protected function normalise(PreparedRow $row, string $field, Closure $normaliser): mixed
    {
        try {
            return $normaliser();
        } catch (Throwable $exception) {
            $row->addError($field, $exception->getMessage());

            return null;
        }
    }
}
