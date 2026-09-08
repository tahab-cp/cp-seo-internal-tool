<?php

namespace Database\Factories;

use App\Actions\Users\AssignUserRoleAction;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function withRole(UserRole $role): static
    {
        return $this->afterCreating(
            fn (User $user) => app(AssignUserRoleAction::class)->handle($user, $role),
        );
    }

    public function superAdmin(): static
    {
        return $this->withRole(UserRole::SuperAdmin);
    }

    public function seoManager(): static
    {
        return $this->withRole(UserRole::SeoManager);
    }

    public function seoExecutive(): static
    {
        return $this->withRole(UserRole::SeoExecutive);
    }
}
