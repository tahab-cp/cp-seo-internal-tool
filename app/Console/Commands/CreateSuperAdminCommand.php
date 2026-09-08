<?php

namespace App\Console\Commands;

use App\Actions\Users\CreateUserAction;
use App\Enums\UserRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateSuperAdminCommand extends Command
{
    protected $signature = 'app:create-super-admin
        {--name= : Full name}
        {--email= : Email address used to sign in}
        {--password= : Password (prompted when omitted)}';

    protected $description = 'Create an active Super Admin user for the Filament panel';

    public function handle(CreateUserAction $createUser): int
    {
        $attributes = [
            'name' => $this->option('name') ?: $this->ask('Name'),
            'email' => $this->option('email') ?: $this->ask('Email address'),
            'password' => $this->option('password') ?: $this->secret('Password'),
        ];

        $validator = Validator::make($attributes, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(8)],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = $createUser->handle($attributes, UserRole::SuperAdmin);

        $this->info("Super Admin [{$user->email}] created. Sign in at ".url('/admin'));

        return self::SUCCESS;
    }
}
