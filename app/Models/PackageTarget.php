<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One monthly deliverable default on a package, e.g. backlinks = 50.
 */
class PackageTarget extends Model
{
    /**
     * Allowed shape of a target key: a lowercase slug such as guest_posts.
     */
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'package_id',
        'target_key',
        'label',
        'target_value',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_value' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}
