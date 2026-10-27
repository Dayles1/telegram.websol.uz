<?php

namespace Database\Factories;

use App\Enums\Role as RoleName;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role_id' => fn () => Role::query()->firstOrCreate(['name' => RoleName::User->value])->id,
        ];
    }

    /** The superadmin is one user: the configured email (see User::isSuperadmin). */
    public function superadmin(): static
    {
        return $this->state(fn () => [
            'email' => config('telegram.superadmin_email'),
            'role_id' => Role::query()->firstOrCreate(['name' => RoleName::Superadmin->value])->id,
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn () => [
            'role_id' => Role::query()->firstOrCreate(['name' => RoleName::Admin->value])->id,
        ]);
    }
}
