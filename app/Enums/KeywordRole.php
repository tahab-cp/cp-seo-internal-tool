<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum KeywordRole: string implements HasLabel
{
    case Primary = 'primary';
    case Secondary = 'secondary';

    public function getLabel(): string
    {
        return match ($this) {
            self::Primary => 'Primary',
            self::Secondary => 'Secondary',
        };
    }
}
