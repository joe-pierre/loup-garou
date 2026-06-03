<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ChatMessage>
 */
class ChatMessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'game_id'   => Game::factory(),
            'player_id' => GamePlayer::factory(),
            'message'   => fake()->sentence(),
            'channel'   => fake()->randomElement(['general', 'werewolves']),
            'round'     => 1,
            'phase'     => fake()->randomElement(['election', 'night', 'day']),
        ];
    }
}
