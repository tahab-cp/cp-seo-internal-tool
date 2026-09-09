<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ImportStatus: string implements HasColor, HasLabel
{
    /** File stored and headers read; nothing validated yet. */
    case Uploaded = 'uploaded';

    /** Mapping applied and every row checked; nothing written to the domain. */
    case Validated = 'validated';

    /** Every row written through the domain actions in one transaction. */
    case Completed = 'completed';

    /** Validation or execution failed; nothing was written (rolled back). */
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Uploaded => 'Uploaded',
            self::Validated => 'Validated',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Uploaded => 'gray',
            self::Validated => 'info',
            self::Completed => 'success',
            self::Failed => 'danger',
        };
    }

    public function isFinal(): bool
    {
        return $this === self::Completed;
    }
}
