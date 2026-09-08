<?php

namespace App\Filament\Resources\Packages\Pages;

use App\Actions\Packages\UpdatePackageAction;
use App\Filament\Resources\Packages\Actions\PackageStatusActions;
use App\Filament\Resources\Packages\PackageResource;
use App\Models\Package;
use App\Models\PackageTarget;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class EditPackage extends EditRecord
{
    protected static string $resource = PackageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PackageStatusActions::deactivate(),
            PackageStatusActions::activate(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Package $package */
        $package = $this->getRecord();

        $data['targets'] = $package->targets
            ->map(fn (PackageTarget $target): array => [
                'label' => $target->label,
                'target_key' => $target->target_key,
                'target_value' => $target->target_value,
                'sort_order' => $target->sort_order,
            ])
            ->values()
            ->all();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Package $record */
        return app(UpdatePackageAction::class)->handle(
            $record,
            Arr::only($data, ['name', 'description', 'is_active']),
            array_values($data['targets'] ?? []),
        );
    }
}
