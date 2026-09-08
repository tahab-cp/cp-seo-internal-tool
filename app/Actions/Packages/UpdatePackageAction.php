<?php

namespace App\Actions\Packages;

use App\Models\Package;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class UpdatePackageAction
{
    public function __construct(
        protected SyncPackageTargetsAction $syncTargets,
    ) {}

    /**
     * Update the package definition. Targets are only touched when a
     * target list is supplied (null leaves them unchanged).
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array{target_key: string, label: string, target_value: int|string, sort_order?: int|string|null}>|null  $targets
     */
    public function handle(Package $package, array $attributes, ?array $targets = null): Package
    {
        return DB::transaction(function () use ($package, $attributes, $targets): Package {
            $package->fill(Arr::only($attributes, ['name', 'description', 'is_active']))->save();

            if ($targets !== null) {
                $package = $this->syncTargets->handle($package, $targets);
            }

            return $package;
        });
    }
}
