<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum BacklinkType: string implements HasColor, HasLabel
{
    case GuestPost = 'guest_post';
    case Citation = 'citation';
    case Profile = 'profile';
    case Forum = 'forum';
    case BlogComment = 'blog_comment';
    case Directory = 'directory';
    case Outreach = 'outreach';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::GuestPost => 'Guest post',
            self::Citation => 'Citation',
            self::Profile => 'Profile',
            self::Forum => 'Forum',
            self::BlogComment => 'Blog comment',
            self::Directory => 'Directory',
            self::Outreach => 'Outreach',
            self::Other => 'Other',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::GuestPost => 'primary',
            self::Outreach => 'info',
            default => 'gray',
        };
    }
}
