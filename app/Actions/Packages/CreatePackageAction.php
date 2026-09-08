<?php

namespace App\Actions\Packages;

use App\Models\Package;
use Illuminate\Support\Facades\DB;

class CreatePackageAction
{
    public function __construct(
        protected SyncPackageTargetsAction $syncTargets,
    ) {}

    /**
     * @param  array{name: string, description?: string|null, is_active?: bool}  $attributes
     * @param  list<array{target_key: string, label: string, target_value: int|string, sort_order?: int|string|null}>  $targets
     */
    public function handle(array $attributes, array $targets = []): Package
    {
        return DB::transaction(function () use ($attributes, $targets): Package {
            $package = Package::query()->create([
                'name' => $attributes['name'],
                'description' => $attributes['description'] ?? null,
                'is_active' => $attributes['is_active'] ?? true,
            ]);

            return $this->syncTargets->handle($package, $targets);
        });
    }
}
