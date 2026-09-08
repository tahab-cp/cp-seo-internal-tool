<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum MonthlyCycleStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case Reporting = 'reporting';
    case Locked = 'locked';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Reporting => 'Reporting',
            self::Locked => 'Locked',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'success',
            self::Reporting => 'warning',
            self::Locked => 'gray',
        };
    }
}
