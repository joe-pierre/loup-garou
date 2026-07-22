<?php

namespace App\Enums;

// TODO : intégration progressive dans les Services, Jobs et Models (Étape 5+)
enum WinnerTeam: string
{
    case VILLAGERS  = 'villagers';
    case WEREWOLVES = 'werewolves';
    case LOVERS     = 'lovers';
}
