<?php

namespace App\Services;

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

    public static function forPlayerCount(int $n): array
    {
        if (! isset(self::TIMERS[$n])) {
            throw new \InvalidArgumentException("Unsupported player count: {$n}");
        }

        return array_merge(self::TIMERS[$n], self::FIXED);
    }
}
