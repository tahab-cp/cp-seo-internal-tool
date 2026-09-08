<?php

namespace App\Filament\Resources\Packages\Pages;

use App\Actions\Packages\CreatePackageAction;
use App\Filament\Resources\Packages\PackageResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class CreatePackage extends CreateRecord
{
    protected static string $resource = PackageResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(CreatePackageAction::class)->handle(
            Arr::only($data, ['name', 'description', 'is_active']),
            array_values($data['targets'] ?? []),
        );
    }
}
