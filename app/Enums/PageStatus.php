<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PageStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case Draft = 'draft';
    case Redirected = 'redirected';
    case Removed = 'removed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Draft => 'Draft',
            self::Redirected => 'Redirected',
            self::Removed => 'Removed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Draft => 'gray',
            self::Redirected => 'warning',
            self::Removed => 'danger',
        };
    }
}
