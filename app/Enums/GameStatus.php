<?php

namespace App\Enums;

// TODO : intégration progressive dans les Services, Jobs et Models (Étape 5+)
enum GameStatus: string
{
    case WAITING           = 'waiting';
    case ELECTING_MAYOR    = 'electing_mayor';
    case NIGHT             = 'night';
    case WOLVES_TURN       = 'wolves_turn';
    case PROCESSING_NIGHT  = 'processing_night';
    case DAY               = 'day';
    case PROCESSING_DAY    = 'processing_day';
    case FINISHED          = 'finished';

    /** Retourne true si le statut est une phase nuit (y compris intermédiaires) */
    public function isNightPhase(): bool
    {
        return in_array($this, [self::NIGHT, self::WOLVES_TURN, self::PROCESSING_NIGHT]);
    }

    /** Retourne true si le statut est une phase jour (y compris intermédiaires) */
    public function isDayPhase(): bool
    {
        return in_array($this, [self::DAY, self::PROCESSING_DAY]);
    }
}
