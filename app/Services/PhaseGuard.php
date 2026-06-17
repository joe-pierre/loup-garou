<?php

namespace App\Services;

use App\Models\Game;

/**
 * Centralise les vérifications de statut de phase pour éviter la duplication
 * de in_array($game->status, [...]) dans 6+ fichiers.
 *
 * Pour ajouter un statut intermédiaire (ex: v1.3), modifier uniquement ce fichier.
 */
class PhaseGuard
{
    /**
     * La partie est en phase nuit (tous statuts nocturnes confondus).
     */
    public static function isNight(Game $game): bool
    {
        return in_array($game->status, ['night', 'wolves_turn', 'processing_night']);
    }

    /**
     * La partie est en phase nuit stricte ou en résolution nocturne
     * (exclut wolves_turn — utilisé pour les guards de jobs nocturnes).
     */
    public static function isNightOrProcessing(Game $game): bool
    {
        return in_array($game->status, ['night', 'processing_night']);
    }

    /**
     * La partie est en phase jour (tous statuts diurnes confondus).
     */
    public static function isDay(Game $game): bool
    {
        return in_array($game->status, ['day', 'processing_day']);
    }

    /**
     * La sorcière peut agir (nuit stricte ou résolution nocturne).
     */
    public static function canWitchAct(Game $game): bool
    {
        return self::isNightOrProcessing($game);
    }

    /**
     * Le chasseur peut tirer (nuit ou jour, tous statuts).
     */
    public static function canHunterShoot(Game $game): bool
    {
        return self::isNight($game) || self::isDay($game);
    }

    /**
     * Le chat général est autorisé.
     */
    public static function canChatGeneral(Game $game): bool
    {
        return in_array($game->status, ['electing_mayor', 'day', 'processing_day']);
    }

    /**
     * Le chat loups est autorisé.
     */
    public static function canChatWolves(Game $game): bool
    {
        return $game->status === 'night';
    }

    /**
     * Le chat fantômes est autorisé.
     */
    public static function canChatDead(Game $game): bool
    {
        return self::isDay($game);
    }
}
