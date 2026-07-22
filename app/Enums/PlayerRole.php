<?php

namespace App\Enums;

// TODO : intégration progressive dans les Services, Jobs et Models (Étape 5+)
enum PlayerRole: string
{
    case VILLAGER   = 'villager';
    case WEREWOLF   = 'werewolf';
    case SEER       = 'seer';
    case WITCH      = 'witch';
    case HUNTER     = 'hunter';
    case CUPIDON    = 'cupidon';
    case WHITE_WOLF = 'white_wolf'; // v1.3+, anticipation

    /** Retourne true si le rôle est dans le camp des loups */
    public function isWerewolfSide(): bool
    {
        return in_array($this, [self::WEREWOLF, self::WHITE_WOLF]);
    }

    /** Retourne true si le rôle est dans le camp du village */
    public function isVillagerSide(): bool
    {
        return in_array($this, [self::VILLAGER, self::SEER, self::WITCH, self::HUNTER]);
    }
}
