<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\Users\CreateUserAction;
use App\Enums\UserRole;
use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        // The enum-backed Select dehydrates a UserRole instance; be tolerant of a raw value too.
        $role = $data['role'] instanceof UserRole ? $data['role'] : UserRole::from((string) $data['role']);

        return app(CreateUserAction::class)->handle(
            Arr::only($data, ['name', 'email', 'password', 'is_active']),
            $role,
        );
    }
}
