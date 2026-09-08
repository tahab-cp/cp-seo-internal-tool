<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A historical target snapshot for one monthly cycle. Written once by
 * CreateMonthlyCycleAction and never recalculated from live configuration.
 */
class MonthlyCycleTarget extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'monthly_cycle_id',
        'target_key',
        'label',
        'target_value',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_value' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<MonthlyCycle, $this>
     */
    public function monthlyCycle(): BelongsTo
    {
        return $this->belongsTo(MonthlyCycle::class);
    }
}
