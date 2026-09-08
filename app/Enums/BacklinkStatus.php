<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum BacklinkStatus: string implements HasColor, HasLabel
{
    case Planned = 'planned';
    case Submitted = 'submitted';
    case Live = 'live';
    case Rejected = 'rejected';
    case Removed = 'removed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Planned => 'Planned',
            self::Submitted => 'Submitted',
            self::Live => 'Live',
            self::Rejected => 'Rejected',
            self::Removed => 'Removed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Planned => 'gray',
            self::Submitted => 'info',
            self::Live => 'success',
            self::Rejected => 'danger',
            self::Removed => 'warning',
        };
    }

    /**
     * Only live links count toward deliverable targets.
     */
    public function counts(): bool
    {
        return $this === self::Live;
    }
}
