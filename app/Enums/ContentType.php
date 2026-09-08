<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ContentType: string implements HasColor, HasLabel
{
    case Blog = 'blog';
    case LandingPage = 'landing_page';
    case ServicePage = 'service_page';
    case LocationPage = 'location_page';
    case GuestContent = 'guest_content';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Blog => 'Blog',
            self::LandingPage => 'Landing page',
            self::ServicePage => 'Service page',
            self::LocationPage => 'Location page',
            self::GuestContent => 'Guest content',
            self::Other => 'Other',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Blog => 'primary',
            self::GuestContent => 'info',
            default => 'gray',
        };
    }

    /**
     * Only blogs count toward the monthly blogs target.
     */
    public function countsAsBlog(): bool
    {
        return $this === self::Blog;
    }
}
