<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMonthlyCycle;
use Database\Factories\Ga4CountryMetricFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One country's monthly audience figures (Audience by Country report
 * section). Identity: MonthlyCycle + normalised country.
 */
class Ga4CountryMetric extends Model
{
    /** @use HasFactory<Ga4CountryMetricFactory> */
    use BelongsToMonthlyCycle, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'monthly_cycle_id',
        'country',
        'active_users',
        'new_users',
        'sessions',
        'engaged_sessions',
        'engagement_rate',
        'event_count',
        'key_events',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active_users' => 'integer',
            'new_users' => 'integer',
            'sessions' => 'integer',
            'engaged_sessions' => 'integer',
            'engagement_rate' => 'decimal:2',
            'event_count' => 'integer',
            'key_events' => 'integer',
        ];
    }
}
