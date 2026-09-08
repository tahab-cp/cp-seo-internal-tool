<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Stored for display only. ReportReadinessService is the authority.
 */
enum ReportSectionStatus: string implements HasColor, HasLabel
{
    case Incomplete = 'incomplete';
    case Complete = 'complete';

    public function getLabel(): string
    {
        return match ($this) {
            self::Incomplete => 'Missing',
            self::Complete => 'Complete',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Incomplete => 'danger',
            self::Complete => 'success',
        };
    }
}
