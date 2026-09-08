<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The known monthly-report sections and their single source of default
 * configuration (title, order, enabled, required). Projects customise
 * these per project; monthly reports snapshot them. No free-form keys.
 */
enum ReportSectionKey: string implements HasLabel
{
    case ExecutiveSummary = 'executive_summary';
    case SiteAuthority = 'site_authority';
    case OrganicSearch = 'organic_search';
    case WebsiteTraffic = 'website_traffic';
    case TopKeywords = 'top_keywords';
    case LandingPages = 'landing_pages';
    case Rankings = 'rankings';
    case AudienceCountry = 'audience_country';
    case Backlinks = 'backlinks';
    case Recommendations = 'recommendations';

    public function getLabel(): string
    {
        return $this->defaultTitle();
    }

    public function defaultTitle(): string
    {
        return match ($this) {
            self::ExecutiveSummary => 'Executive Summary',
            self::SiteAuthority => 'Site Authority',
            self::OrganicSearch => 'Organic Search',
            self::WebsiteTraffic => 'Website Traffic',
            self::TopKeywords => 'Top Keywords',
            self::LandingPages => 'Landing Pages',
            self::Rankings => 'Rankings',
            self::AudienceCountry => 'Audience by Country',
            self::Backlinks => 'Backlinks',
            self::Recommendations => 'Recommendations',
        };
    }

    /**
     * 10, 20, 30 … so projects can slot custom orders between defaults.
     */
    public function defaultSortOrder(): int
    {
        return (array_search($this, self::cases(), true) + 1) * 10;
    }

    public function isEnabledByDefault(): bool
    {
        return true;
    }

    /**
     * Audience by Country is optional by default: GA4 country data is often
     * unavailable for smaller sites. Everything else is required.
     */
    public function isRequiredByDefault(): bool
    {
        return $this !== self::AudienceCountry;
    }

    /**
     * What the readiness rule for this section needs, in plain words.
     */
    public function readinessRequirement(): string
    {
        return match ($this) {
            self::ExecutiveSummary => 'Write the executive summary.',
            self::SiteAuthority => 'Enter the month\'s authority metrics.',
            self::OrganicSearch => 'Enter the month\'s Search Console summary.',
            self::WebsiteTraffic => 'Enter the month\'s Google Analytics summary.',
            self::TopKeywords => 'Enter at least one Search Console query.',
            self::LandingPages => 'Enter at least one Search Console landing page.',
            self::Rankings => 'Record a ranking for every active keyword this month.',
            self::AudienceCountry => 'Enter at least one Google Analytics country row.',
            self::Backlinks => 'Record a backlink, or configure a backlinks / guest posts target.',
            self::Recommendations => 'Add at least one recommendation or next-month-focus note.',
        };
    }

    /**
     * @return array{section_key: string, title: string, is_enabled: bool, is_required: bool, sort_order: int}
     */
    public function defaults(): array
    {
        return [
            'section_key' => $this->value,
            'title' => $this->defaultTitle(),
            'is_enabled' => $this->isEnabledByDefault(),
            'is_required' => $this->isRequiredByDefault(),
            'sort_order' => $this->defaultSortOrder(),
        ];
    }

    /**
     * The complete default configuration, in default order.
     *
     * @return list<array{section_key: string, title: string, is_enabled: bool, is_required: bool, sort_order: int}>
     */
    public static function defaultDefinitions(): array
    {
        return array_map(fn (self $key): array => $key->defaults(), self::cases());
    }
}
