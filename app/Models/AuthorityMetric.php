<?php

namespace App\Models;

use App\Enums\DataSource;
use App\Models\Concerns\BelongsToMonthlyCycle;
use Database\Factories\AuthorityMetricFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The month's site-authority snapshot from external tools (Site Authority
 * report section). Exactly one per MonthlyCycle.
 *
 * backlinks_count / referring_domains_count are the vendor's site-wide
 * totals. They are unrelated to the operational Backlink records and never
 * feed the monthly backlinks target.
 */
class AuthorityMetric extends Model
{
    /** @use HasFactory<AuthorityMetricFactory> */
    use BelongsToMonthlyCycle, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'monthly_cycle_id',
        'moz_domain_authority',
        'moz_linking_root_domains',
        'ahrefs_domain_rating',
        'ahrefs_url_rating',
        'backlinks_count',
        'referring_domains_count',
        'source',
        'synced_at',
        'entered_by',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'moz_domain_authority' => 'integer',
            'moz_linking_root_domains' => 'integer',
            'ahrefs_domain_rating' => 'decimal:1',
            'ahrefs_url_rating' => 'decimal:1',
            'backlinks_count' => 'integer',
            'referring_domains_count' => 'integer',
            'source' => DataSource::class,
            'synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }
}
