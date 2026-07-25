<?php

namespace App\Services;

use App\Models\Game;

/**
 * Calcule et expose les timers de phase d'une partie.
 *
 * Priorité de lecture pour les timers configurables :
 *   1. $game->settings['timers'][$key] (override host, si valeur entière)
 *   2. config('game.timers.$key') (valeur par défaut)
 *
 * Timers NON_CONFIGURABLE (toujours lus depuis config(), jamais surchargés) :
 *   'reconnection', 'ready_timeout', 'night_start_delay', 'mayor_reveal'
 *
 * Règle absolue : accéder aux timers via $game->timer('key'), jamais config('game.timers.x') directement.
 * TIMERS : valeurs par défaut par effectif (6/8/10/12 joueurs) — initialisées dans games.timers à startGame().
 * FIXED  : timers indépendants de l'effectif (mayor_election, mayor_succession, etc.).
 */
class TimerCalculator
{
    private const TIMERS = [
        6  => ['seer' => 20, 'werewolves' => 25, 'day_vote' => 115],
        8  => ['seer' => 25, 'werewolves' => 45, 'day_vote' => 115],
        10 => ['seer' => 30, 'werewolves' => 45, 'day_vote' => 115],
        12 => ['seer' => 35, 'werewolves' => 45, 'day_vote' => 115],
    ];

    private const FIXED = [
        'mayor_election'   => 30,
        'mayor_succession' => 15,
        'reconnection'     => 30,
        'ready_timeout'    => 60,
        'mayor_reveal'     => 5,
    ];

    // Timers fixes — toujours ignorés même si présents dans settings['timers']
    private const NON_CONFIGURABLE = ['reconnection', 'ready_timeout', 'night_start_delay', 'mayor_reveal'];

    /**
     * Retourne le tableau complet des timers pour un effectif donné (timers variables + FIXED).
     * Utilisé par GameService::startGame() pour initialiser games.timers au démarrage.
     * Note : games.timers devient une donnée dormante pour les clés configurables dès que
     * settings['timers'] est peuplé par le host — TimerCalculator::get() ne le lit plus.
     *
     * @param  int $n Nombre de joueurs (valeurs supportées : 6, 8, 10, 12)
     * @return array<string, int> [nom_timer => secondes]
     * @throws \InvalidArgumentException Si $n n'est pas une clé de la constante TIMERS
     */
    public static function forPlayerCount(int $n): array
    {
        if (! isset(self::TIMERS[$n])) {
            throw new \InvalidArgumentException("Unsupported player count: {$n}");
        }

        return array_merge(self::TIMERS[$n], self::FIXED);
    }

    /**
     * Retourne la valeur d'un timer en secondes, avec priorité settings > config.
     * Si le timer est dans NON_CONFIGURABLE, lit toujours config() directement (jamais settings['timers']).
     * Fallback config() avec valeur par défaut de 30 secondes si la clé est absente.
     *
     * @param  Game   $game La partie concernée (pour lire settings['timers'])
     * @param  string $key  Nom interne du timer (ex. 'seer', 'day_vote', 'mayor_election')
     * @return int           Durée en secondes
     */
    public static function get(Game $game, string $key): int
    {
        if (in_array($key, self::NON_CONFIGURABLE, true)) {
            return config("game.timers.{$key}");
        }

        $settings = $game->settings['timers'] ?? [];

        if (isset($settings[$key]) && is_int($settings[$key])) {
            return $settings[$key];
        }

        return config("game.timers.{$key}", 30);
    }
}
