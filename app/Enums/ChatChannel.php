<?php

namespace App\Enums;

// TODO : intégration progressive dans les Services, Jobs et Models (Étape 5+)
enum ChatChannel: string
{
    case GENERAL    = 'general';
    case WEREWOLVES = 'werewolves';
    case DEAD       = 'dead';
}
