<?php

namespace App\Enums;

// TODO : intégration progressive dans les Services, Jobs et Models (Étape 5+)
enum ActionType: string
{
    case MAYOR_VOTE       = 'mayor_vote';
    case NIGHT_VOTE       = 'night_vote';
    case DAY_VOTE         = 'day_vote';
    case SEER_CHECK       = 'seer_check';
    case MAYOR_SUCCESSION = 'mayor_succession';
    case WITCH_HEAL       = 'witch_heal';
    case WITCH_KILL       = 'witch_kill';
    case WITCH_PASS       = 'witch_pass';
    case HUNTER_SHOT      = 'hunter_shot';
    case READY            = 'ready';
    case CUPIDON_LINK     = 'cupidon_link';
}
