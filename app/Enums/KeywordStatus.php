<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum KeywordStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case Paused = 'paused';
    case Archived = 'archived';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::Archived => 'Archived',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Paused => 'warning',
            self::Archived => 'gray',
        };
    }
}
