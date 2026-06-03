<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'google_id' => fake()->unique()->numerify('####################'),
            'email'     => fake()->unique()->safeEmail(),
            'name'      => fake()->name(),
        ];
    }
}
