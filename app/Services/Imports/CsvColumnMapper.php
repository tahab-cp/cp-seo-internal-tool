<?php

namespace App\Services\Imports;

use App\Exceptions\CsvImportException;
use App\Support\Imports\ImportField;

/**
 * Maps CSV headers onto application fields. Suggestions are exact matches
 * only (after trimming, lower-casing and squashing punctuation to
 * underscores) against the field key, its label and its documented
 * aliases; nothing fuzzy.
 */
class CsvColumnMapper
{
    /**
     * @param  list<string>  $headers
     * @param  list<ImportField>  $fields
     * @return array<string, string> field key => header
     */
    public function suggest(array $headers, array $fields): array
    {
        $byNormalised = [];

        foreach ($headers as $header) {
            $byNormalised[$this->normalise($header)] ??= $header;
        }

        $mapping = [];
        $used = [];

        foreach ($fields as $field) {
            foreach ([$field->key, $field->label, ...$field->aliases] as $candidate) {
                $header = $byNormalised[$this->normalise($candidate)] ?? null;

                if ($header !== null && ! isset($used[$header])) {
                    $mapping[$field->key] = $header;
                    $used[$header] = true;

                    break;
                }
            }
        }

        return $mapping;
    }

    /**
     * Cleans a user-submitted mapping and enforces that every required
     * field is mapped, every mapped header exists and no header is used
     * twice. Throws CsvImportException with a user-safe message.
     *
     * @param  array<string, mixed>  $mapping
     * @param  list<string>  $headers
     * @param  list<ImportField>  $fields
     * @return array<string, string>
     */
    public function validate(array $mapping, array $headers, array $fields): array
    {
        $clean = [];
        $used = [];
        $known = array_flip($headers);

        foreach ($fields as $field) {
            $header = $mapping[$field->key] ?? null;
            $header = is_string($header) ? trim($header) : '';

            if ($header === '') {
                if ($field->required) {
                    throw new CsvImportException("The required field \"{$field->label}\" is not mapped to a CSV column.");
                }

                continue;
            }

            if (! isset($known[$header])) {
                throw new CsvImportException("The CSV has no column named \"{$header}\" (mapped to \"{$field->label}\").");
            }

            if (isset($used[$header])) {
                throw new CsvImportException("The CSV column \"{$header}\" is mapped to more than one field.");
            }

            $used[$header] = true;
            $clean[$field->key] = $header;
        }

        return $clean;
    }

    public function normalise(string $header): string
    {
        $value = mb_strtolower(trim($header));
        $value = preg_replace('/[^a-z0-9]+/u', '_', $value) ?? $value;

        return trim($value, '_');
    }
}
