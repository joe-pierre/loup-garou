<?php

namespace App\Events\Game;

use App\Models\Game;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Un joueur a confirmé avoir pris connaissance de son rôle (écran de révélation).
 *
 * Canal : PUBLIC — game.{gameId}
 *
 * Déclencheur : GameService::markReady() depuis ActionController::ready().
 */
class PlayerReady implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Game $game,
        public readonly int $nbReady,
        public readonly int $total,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel("game.{$this->game->id}")];
    }

    public function broadcastAs(): string
    {
        return 'player.ready';
    }

    /**
     * @return array{
     *   nb_ready: int,  // nombre de joueurs ayant confirmé leur rôle
     *   total: int,     // nombre total de joueurs dans la partie
     * }
     */
    public function broadcastWith(): array
    {
        return [
            'nb_ready' => $this->nbReady,
            'total'    => $this->total,
        ];
    }
}
