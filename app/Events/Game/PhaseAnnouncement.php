<?php

namespace App\Events\Game;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Annonce visuelle de transition de phase (overlay plein écran).
 *
 * Canal : PUBLIC — game.{gameId}
 *
 * Déclencheur : PhaseManager::startNight() (night_fall), startDay() (day_break),
 *   GameService::markReady() (mayor_election), ProcessMayorSuccession (mayor_succession),
 *   WinConditionChecker::check() (game_finished), GameService::cancelGame() (game_cancelled).
 *
 * Note : broadcasté AVANT l'event de phase principal (NightStarted, DayStarted, etc.).
 * Les types seer_turn et werewolves_turn ne sont JAMAIS broadcastés ici — canal public uniquement.
 *
 * @property int    $gameId        Identifiant de la partie.
 * @property string $type          Type d'annonce (night_fall, day_break, mayor_election, etc.).
 * @property string $messagePublic Message neutre affiché à tous les joueurs.
 * @property int    $durationMs    Durée d'affichage de l'overlay côté client (en millisecondes).
 */
class PhaseAnnouncement implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int    $gameId,
        public string $type,
        public string $messagePublic,
        public int    $durationMs
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel("game.{$this->gameId}")];
    }

    public function broadcastAs(): string
    {
        return 'phase.announcement';
    }
}
