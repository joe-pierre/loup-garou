<?php

return [
    'timers' => [
        'mayor_election'   => 30,
        'seer'             => 30,
        'werewolves'       => 30,
        'mayor_succession' => 15,
        'day_vote'         => 90,
        'reconnection'     => 30,
        'ready_timeout'    => 60,
    ],

    // 'auto' = floor(n * 0.2) min 1 | 'fill' = reste des joueurs | int = fixe
    'roles' => [
        'seer'     => 1,
        'werewolf' => 'auto',
        'villager' => 'fill',
    ],
];
