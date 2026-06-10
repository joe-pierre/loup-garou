<?php

return [
    'timers' => [
        'mayor_election'   => 30,
        'seer'             => 30,
        'werewolves'       => 30,
        'mayor_succession' => 5,
        'day_vote'         => 90,
        'reconnection'     => 30,
        'ready_timeout'    => 60,
        'mayor_reveal'     => 5,
        // Délai entre NightStarted broadcasté et ProcessSeerTurn dispatché,
        // pour laisser le temps aux clients de se rediriger vers /night
        // et de s'abonner au canal privé avant que SeerTurnStarted parte.
        'night_start_delay' => 4,
    ],

    // 'auto' = floor(n * 0.2) min 1 | 'fill' = reste des joueurs | int = fixe
    // L'ordre est significatif : 'fill' doit être en dernier
    'roles' => [
        'seer'     => 1,
        'werewolf' => 'auto',
        'villager' => 'fill',
    ],

    // Table de référence v1.1 (prioritaire sur la formule)
    // Cas spéciaux : 8 et 12 ne correspondent pas à floor(n * 0.2)
    // Ajouter ici les overrides pour les nouveaux maxPlayers en v1.2
    'werewolf_count_overrides' => [
        6  => 1,
        8  => 2,
        10 => 2,
        12 => 3,
    ],
];