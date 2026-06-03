<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\GameAction>
 */
class GameActionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'game_id'          => Game::factory(),
            'player_id'        => GamePlayer::factory(),
            'type'             => fake()->randomElement(['mayor_vote', 'night_vote', 'day_vote', 'seer_check', 'mayor_succession', 'werewolf_chat', 'ready']),
            'weight'           => 1,
            'target_player_id' => null,
            'round'            => 1,
            'phase'            => fake()->randomElement(['election', 'night', 'day']),
        ];
    }
}
