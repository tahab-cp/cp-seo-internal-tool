<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMonthlyCycle;
use Database\Factories\GscPageMetricFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One landing page's monthly search performance (Landing Pages report
 * section). page_url is the analytics source value and is kept even when
 * no Page master record matches; page_id is an optional mapping.
 * Identity: MonthlyCycle + page_url.
 */
class GscPageMetric extends Model
{
    /** @use HasFactory<GscPageMetricFactory> */
    use BelongsToMonthlyCycle, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'monthly_cycle_id',
        'page_id',
        'page_url',
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

    /**
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class)->withTrashed();
    }
}
