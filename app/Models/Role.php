<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'name',
        'description',
        'is_system',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'key' => UserRole::class,
            'is_system' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * Find the persisted system role for the given key, creating it if missing.
     */
    public static function forKey(UserRole $key): self
    {
        return static::query()->firstOrCreate(
            ['key' => $key->value],
            [
                'name' => $key->getLabel(),
                'description' => $key->description(),
                'is_system' => true,
            ],
        );
    }
}
