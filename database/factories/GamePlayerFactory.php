<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\GamePlayer>
 */
class GamePlayerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'game_id'     => Game::factory(),
            'user_id'     => User::factory(),
            'pseudo'      => fake()->userName(),
            'role'        => null,
            'is_alive'    => true,
            'is_host'     => false,
            'is_mayor'    => false,
            'is_inactive' => false,
            'is_ready'    => false,
            'joined_at'   => now(),
        ];
    }

    public function werewolf(): static
    {
        return $this->state(['role' => 'werewolf']);
    }

    public function seer(): static
    {
        return $this->state(['role' => 'seer']);
    }

    public function witch(): static
    {
        return $this->state(['role' => 'witch']);
    }

    public function hunter(): static
    {
        return $this->state(['role' => 'hunter']);
    }

    public function villager(): static
    {
        return $this->state(['role' => 'villager']);
    }

    public function host(): static
    {
        return $this->state(['is_host' => true]);
    }

    public function dead(): static
    {
        return $this->state(['is_alive' => false]);
    }
}
