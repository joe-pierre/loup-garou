<?php

namespace App\Events\Game;

use App\Models\Game;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Début de la phase de nuit.
 *
 * Canal : PUBLIC — game.{gameId}
 *
 * Déclencheur : PhaseManager::startNight() après résolution du vote de jour
 *   ou après l'élection du maire (premier round).
 *
 * Note : cet event est différé pendant l'overlay d'annonce (PhaseAnnouncement).
 */
class NightStarted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Game $game) {}

    public function broadcastOn(): array
    {
        return [new Channel("game.{$this->game->id}")];
    }

    public function broadcastAs(): string
    {
        return 'night.started';
    }

    /**
     * @return array{
     *   round: int,  // numéro du round courant
     *   timer: int,  // durée du tour de la voyante en secondes (sert de durée de nuit côté client)
     * }
     */
    public function broadcastWith(): array
    {
        return [
            'round' => $this->game->round,
            'timer' => $this->game->timer('seer'),
        ];
    }
}
