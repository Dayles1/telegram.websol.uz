<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Catalog>
 */
class CatalogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->words(2, true),
            'peers' => ['@'.fake()->unique()->userName(), '@'.fake()->unique()->userName()],
            'is_global' => false,
            'timer_minutes' => null,
        ];
    }

    /** @param list<string> $peers */
    public function peers(array $peers): static
    {
        return $this->state(fn () => ['peers' => $peers]);
    }
}
