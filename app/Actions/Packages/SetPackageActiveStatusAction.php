<?php

namespace App\Actions\Packages;

use App\Models\Package;

class SetPackageActiveStatusAction
{
    /**
     * Activate or deactivate a package. Deactivation only stops new
     * assignments: projects already on the package keep it, and its
     * targets are untouched.
     */
    public function handle(Package $package, bool $isActive): Package
    {
        $package->forceFill(['is_active' => $isActive])->save();

        return $package;
    }
}
