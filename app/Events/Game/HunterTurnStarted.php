<?php

namespace App\Events\Game;

use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Début du tour du chasseur après son élimination.
 *
 * Canal : PRIVÉ — game.{gameId}.player.{hunterPlayerId}
 * Seul le chasseur reçoit cet event. Les autres joueurs ne doivent pas
 * savoir que le chasseur est en train de choisir sa cible.
 *
 * Déclencheur : ProcessHunterTurn::handle() dès qu'un joueur avec le rôle
 *   hunter est éliminé (nuit ou jour).
 */
class HunterTurnStarted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Game $game,
        public readonly GamePlayer $hunter,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("game.{$this->game->id}.player.{$this->hunter->id}")];
    }

    public function broadcastAs(): string
    {
        return 'hunter.turn.started';
    }

    /**
     * @return array{
     *   timer: int,  // durée accordée au chasseur pour choisir sa cible en secondes
     * }
     */
    public function broadcastWith(): array
    {
        return [
            'timer' => $this->game->timer('hunter'),
        ];
    }
}
