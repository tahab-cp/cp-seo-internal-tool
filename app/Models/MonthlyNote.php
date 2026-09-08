<?php

namespace App\Models;

use App\Enums\MonthlyNoteType;
use App\Models\Concerns\BelongsToMonthlyCycle;
use Database\Factories\MonthlyNoteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One narrative note for a reporting month (win, challenge, observation,
 * recommendation, next-month focus). Reporting-period data: immutable once
 * the cycle is locked.
 */
class MonthlyNote extends Model
{
    /** @use HasFactory<MonthlyNoteFactory> */
    use BelongsToMonthlyCycle, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'monthly_cycle_id',
        'type',
        'title',
        'body',
        'sort_order',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MonthlyNoteType::class,
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<MonthlyNote>  $query
     * @return Builder<MonthlyNote>
     */
    public function scopeOfType(Builder $query, MonthlyNoteType ...$types): Builder
    {
        return $query->whereIn('type', array_map(fn (MonthlyNoteType $type): string => $type->value, $types));
    }

    /**
     * @param  Builder<MonthlyNote>  $query
     * @return Builder<MonthlyNote>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
