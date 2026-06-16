<?php

return [
    /*
     * Couleurs des avatars joueurs (assignées par player_id % count)
     * Utilisées dans day.blade.php, waiting-room.blade.php, mayor-election.blade.php
     */
    'avatar_colors' => [
        '#c9a84c', '#a78bfa', '#4ade80', '#ff4444',
        '#38bdf8', '#fb923c', '#f472b6', '#34d399',
    ],

    /*
     * Labels des rôles pour l'affichage côté serveur (Blade)
     * Utilisés dans finished.blade.php, history.blade.php, day.blade.php
     */
    'role_labels' => [
        'villager'   => 'Villageois',
        'werewolf'   => 'Loup-Garou',
        'seer'       => 'Voyante',
        'witch'      => 'Sorcière',
        'hunter'     => 'Chasseur',
        'white_wolf' => 'Loup Blanc',
    ],

    /*
     * Labels des rôles avec emoji pour les notifications push
     */
    'role_labels_emoji' => [
        'villager' => '🧑‍🌾 Villageois',
        'werewolf' => '🐺 Loup-Garou',
        'seer'     => '🔮 Voyante',
        'witch'    => '🧙‍♀️ Sorcière',
        'hunter'   => '🏹 Chasseur',
    ],
];
