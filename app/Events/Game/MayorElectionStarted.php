<?php

namespace App\Events\Game;

use App\Models\Game;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Début de l'élection du maire en début de partie.
 *
 * Canal : PUBLIC — game.{gameId}
 *
 * Déclencheur : GameService::startGame() après distribution des rôles,
 *   via la transition Workflow waiting → electing_mayor.
 */
class MayorElectionStarted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Game $game) {}

    public function broadcastOn(): array
    {
        return [new Channel("game.{$this->game->id}")];
    }

    public function broadcastAs(): string
    {
        return 'mayor.election.started';
    }

    /**
     * @return array{
     *   timer: int,  // durée de la phase d'élection du maire en secondes
     * }
     */
    public function broadcastWith(): array
    {
        return [
            'timer' => $this->game->timer('mayor_election'),
        ];
    }
}
