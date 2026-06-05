<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Exclusion>
 */
class ExclusionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'game_id'     => Game::factory(),
            'user_id'     => User::factory(),
            'player_id'   => null,
            'reason'      => fake()->sentence(),
            'excluded_at' => now(),
        ];
    }
}
