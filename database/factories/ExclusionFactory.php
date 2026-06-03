<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\GamePlayer;
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
            'player_id'   => GamePlayer::factory(),
            'reason'      => fake()->sentence(),
            'excluded_at' => now(),
        ];
    }
}
