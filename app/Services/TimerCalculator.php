<?php

namespace App\Services;

use App\Models\Game;

class TimerCalculator
{
    private const TIMERS = [
        6  => ['seer' => 20, 'werewolves' => 25, 'day_vote' => 60],
        8  => ['seer' => 25, 'werewolves' => 30, 'day_vote' => 75],
        10 => ['seer' => 30, 'werewolves' => 35, 'day_vote' => 90],
        12 => ['seer' => 35, 'werewolves' => 40, 'day_vote' => 105],
    ];

    private const FIXED = [
        'mayor_election'   => 30,
        'mayor_succession' => 15,
        'reconnection'     => 30,
        'ready_timeout'    => 60,
        'mayor_reveal'     => 5,
    ];

    // Timers fixes — toujours ignorés même si présents dans settings['timers']
    private const NON_CONFIGURABLE = ['reconnection', 'ready_timeout', 'night_start_delay', 'mayor_reveal'];

    public static function forPlayerCount(int $n): array
    {
        if (! isset(self::TIMERS[$n])) {
            throw new \InvalidArgumentException("Unsupported player count: {$n}");
        }

        return array_merge(self::TIMERS[$n], self::FIXED);
    }

    public static function get(Game $game, string $key): int
    {
        if (in_array($key, self::NON_CONFIGURABLE, true)) {
            return config("game.timers.{$key}");
        }

        $settings = $game->settings['timers'] ?? [];

        if (isset($settings[$key]) && is_int($settings[$key])) {
            return $settings[$key];
        }

        return config("game.timers.{$key}", 30);
    }
}
