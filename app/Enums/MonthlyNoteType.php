<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Narrative captured during the month so the report writes itself later.
 */
enum MonthlyNoteType: string implements HasColor, HasLabel
{
    case Win = 'win';
    case Challenge = 'challenge';
    case Observation = 'observation';
    case Recommendation = 'recommendation';
    case NextMonthFocus = 'next_month_focus';

    public function getLabel(): string
    {
        return match ($this) {
            self::Win => 'Win',
            self::Challenge => 'Challenge',
            self::Observation => 'Observation',
            self::Recommendation => 'Recommendation',
            self::NextMonthFocus => 'Next month focus',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Win => 'success',
            self::Challenge => 'danger',
            self::Observation => 'gray',
            self::Recommendation => 'primary',
            self::NextMonthFocus => 'info',
        };
    }

    /**
     * The Monthly Work screen groups notes into three lanes.
     */
    public function group(): string
    {
        return match ($this) {
            self::Win => 'wins',
            self::Challenge, self::Observation => 'challenges',
            self::Recommendation, self::NextMonthFocus => 'recommendations',
        };
    }

    /**
     * Types that satisfy the Recommendations report section.
     *
     * @return list<self>
     */
    public static function recommendationTypes(): array
    {
        return [self::Recommendation, self::NextMonthFocus];
    }
}
