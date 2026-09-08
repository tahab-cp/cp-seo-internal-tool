<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\Users\AssignUserRoleAction;
use App\Actions\Users\SetUserActiveStatusAction;
use App\Actions\Users\UpdateUserAction;
use App\Enums\UserRole;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var User $user */
        $user = $this->getRecord();

        $data['role'] = $user->role();

        return $data;
    }

    /**
     * Profile changes, role assignment and activation are separate
     * permissions, so each is applied only when the actor holds it.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $record */
        $actor = Filament::auth()->user();

        return DB::transaction(function () use ($record, $data, $actor): User {
            $record = app(UpdateUserAction::class)->handle(
                $record,
                Arr::only($data, ['name', 'email', 'password']),
            );

            if (array_key_exists('role', $data) && $actor->can('assignRole', $record)) {
                // The enum-backed Select dehydrates a UserRole instance; be tolerant of a raw value too.
                $role = $data['role'] instanceof UserRole ? $data['role'] : UserRole::from((string) $data['role']);

                $record = app(AssignUserRoleAction::class)->handle($record, $role);
            }

            if (array_key_exists('is_active', $data)) {
                $isActive = (bool) $data['is_active'];
                $ability = $isActive ? 'activate' : 'deactivate';

                if ($isActive !== $record->is_active && $actor->can($ability, $record)) {
                    $record = app(SetUserActiveStatusAction::class)->handle($record, $isActive);
                }
            }

            return $record;
        });
    }
}
