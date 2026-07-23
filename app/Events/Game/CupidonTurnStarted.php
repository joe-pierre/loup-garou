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
 * Début du tour de Cupidon (round 1 exclusivement, avant la Voyante).
 *
 * Canal : PRIVÉ — game.{gameId}.player.{cupidonPlayerId}
 * Seul Cupidon reçoit cet event. Les autres joueurs ne savent jamais
 * qu'un tour Cupidon a eu lieu (cf. SPEC_CUPIDON.md §1 : confidentialité totale).
 *
 * Déclencheur : ProcessCupidonTurn::handle(), au tout début de la nuit 1.
 * Branché dans PhaseManager::startNight() (round === 1, Cupidon distribué) — voir DECISIONS.md.
 */
class CupidonTurnStarted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Game $game,
        public readonly GamePlayer $cupidon,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("game.{$this->game->id}.player.{$this->cupidon->id}")];
    }

    public function broadcastAs(): string
    {
        return 'cupidon.turn.started';
    }

    /**
     * @return array{
     *   timer: int,  // durée du tour de Cupidon en secondes
     * }
     */
    public function broadcastWith(): array
    {
        return [
            'timer' => $this->game->timer('cupidon'),
        ];
    }
}
