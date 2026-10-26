<?php

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An authorized account by default. The session path points into the test
 * sessions directory; the fake gateway never reads it.
 *
 * @extends Factory<\App\Models\UserPhone>
 */
class UserPhoneFactory extends Factory
{
    public function definition(): array
    {
        $phone = '+99890'.fake()->unique()->numerify('#######');

        return [
            'user_id' => User::factory(),
            'phone' => $phone,
            'status' => AccountStatus::Authorized,
            'telegram_user_id' => (string) fake()->numberBetween(100000, 999999999),
            'first_name' => fake()->firstName(),
            'session_path' => storage_path('framework/testing/sessions/'.ltrim($phone, '+').'.madeline'),
            'authorized_at' => now()->subDay(),
        ];
    }

    public function status(AccountStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
