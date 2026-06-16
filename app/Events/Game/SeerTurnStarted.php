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
 * Début du tour de la voyante.
 *
 * Canal : PRIVÉ — game.{gameId}.player.{seerPlayerId}
 * Seule la voyante reçoit cet event. Les autres joueurs ne savent pas
 * que la voyante est en train d'inspecter (cf. SPEC : seer_turn jamais public).
 *
 * Déclencheur : ProcessSeerTurn::handle() au début de chaque nuit,
 *   après NightStarted sur le canal public.
 */
class SeerTurnStarted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Game $game,
        public readonly GamePlayer $seer,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("game.{$this->game->id}.player.{$this->seer->id}")];
    }

    public function broadcastAs(): string
    {
        return 'seer.turn.started';
    }

    /**
     * @return array{
     *   timer: int,  // durée du tour de la voyante en secondes
     * }
     */
    public function broadcastWith(): array
    {
        return [
            'timer' => $this->game->timer('seer'),
        ];
    }
}
