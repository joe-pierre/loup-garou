<?php

return [
    'timers' => [
        'mayor_election'   => 30,
        'seer'             => 30,
        'werewolves'       => 30,
        'witch'            => 30,
        'hunter'           => 15,
        'mayor_succession' => 5,
        'day_vote'         => 90,
        'reconnection'     => 30,
        'ready_timeout'    => 60,
        'mayor_reveal'     => 8,
        // Délai entre NightStarted broadcasté et ProcessSeerTurn dispatché,
        // pour laisser le temps aux clients de se rediriger vers /night
        // et de s'abonner au canal privé avant que SeerTurnStarted parte.
        'night_start_delay' => 8,

        // Limites de configuration des timers par le host (Étape 3, v1.2)
        // host_configurable = false → timer toujours ignoré dans settings['timers']
        'limits' => [
            'mayor_election'    => ['min' => 20, 'max' => 60,  'host_configurable' => true],
            'seer'              => ['min' => 15, 'max' => 60,  'host_configurable' => true],
            'werewolves'        => ['min' => 15, 'max' => 60,  'host_configurable' => true],
            'witch'             => ['min' => 15, 'max' => 60,  'host_configurable' => true],
            'hunter'            => ['min' => 10, 'max' => 30,  'host_configurable' => true],
            'mayor_succession'  => ['min' => 10, 'max' => 30,  'host_configurable' => true],
            'day_vote'          => ['min' => 60, 'max' => 180, 'host_configurable' => true],
            'reconnection'      => ['min' => 30, 'max' => 30,  'host_configurable' => false],
            'ready_timeout'     => ['min' => 60, 'max' => 60,  'host_configurable' => false],
            'night_start_delay' => ['min' => 4,  'max' => 4,   'host_configurable' => false],
            'mayor_reveal'      => ['min' => 5,  'max' => 5,   'host_configurable' => false],
        ],
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