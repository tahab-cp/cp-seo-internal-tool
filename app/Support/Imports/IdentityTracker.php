<?php

namespace App\Support\Imports;

/**
 * Remembers record identities seen earlier in the same CSV so a duplicate
 * can be reported deterministically ("duplicates row N").
 */
final class IdentityTracker
{
    /**
     * @var array<string, int> identity => first row number
     */
    private array $seen = [];

    /**
     * Records the identity and returns the earlier row number when it was
     * already seen, null when this is the first occurrence.
     */
    public function remember(string $identity, int $rowNumber): ?int
    {
        if (isset($this->seen[$identity])) {
            return $this->seen[$identity];
        }

        $this->seen[$identity] = $rowNumber;

        return null;
    }
}
