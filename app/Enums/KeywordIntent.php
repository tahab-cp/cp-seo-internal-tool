<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum KeywordIntent: string implements HasColor, HasLabel
{
    case Informational = 'informational';
    case Navigational = 'navigational';
    case Commercial = 'commercial';
    case Transactional = 'transactional';
    case Local = 'local';
    case Unknown = 'unknown';

    public function getLabel(): string
    {
        return match ($this) {
            self::Informational => 'Informational',
            self::Navigational => 'Navigational',
            self::Commercial => 'Commercial',
            self::Transactional => 'Transactional',
            self::Local => 'Local',
            self::Unknown => 'Unknown',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Informational => 'info',
            self::Navigational => 'gray',
            self::Commercial => 'warning',
            self::Transactional => 'success',
            self::Local => 'primary',
            self::Unknown => 'gray',
        };
    }
}
