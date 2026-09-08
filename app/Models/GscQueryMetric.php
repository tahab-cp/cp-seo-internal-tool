<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMonthlyCycle;
use Database\Factories\GscQueryMetricFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One search query's monthly performance (Top Keywords report section).
 * Search data, deliberately not linked to tracked Keyword records.
 * Identity: MonthlyCycle + normalised query.
 */
class GscQueryMetric extends Model
{
    /** @use HasFactory<GscQueryMetricFactory> */
    use BelongsToMonthlyCycle, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'monthly_cycle_id',
        'query',
        'clicks',
        'impressions',
        'ctr',
        'average_position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'clicks' => 'integer',
            'impressions' => 'integer',
            'ctr' => 'decimal:2',
            'average_position' => 'decimal:2',
        ];
    }
}
