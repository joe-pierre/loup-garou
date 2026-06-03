<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Game>
 */
class GameFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code'           => strtoupper(Str::random(6)),
            'status'         => 'waiting',
            'max_players'    => fake()->randomElement([6, 8, 10, 12]),
            'round'          => 0,
            'phase_deadline' => null,
            'winner_team'    => null,
            'started_at'     => null,
            'finished_at'    => null,
        ];
    }

    public function inProgress(): static
    {
        return $this->state(['status' => 'night', 'started_at' => now(), 'round' => 1]);
    }

    public function finished(): static
    {
        return $this->state([
            'status'      => 'finished',
            'started_at'  => now()->subHour(),
            'finished_at' => now(),
            'winner_team' => fake()->randomElement(['villagers', 'werewolves']),
        ]);
    }
}
