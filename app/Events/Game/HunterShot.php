<?php

namespace App\Events\Game;

use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Le chasseur a tiré sur une cible lors de son tour.
 *
 * Canal : PUBLIC — game.{gameId}
 *
 * Déclencheur : GameService::hunterShoot() depuis ActionController::hunterShoot(),
 *   ou ProcessHunterAutoAction si le chasseur n'a pas agi avant expiration du timer.
 *   Suivi immédiatement d'un PlayerEliminated pour la cible abattue.
 */
class HunterShot implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Game $game,
        public readonly GamePlayer $hunter,
        public readonly GamePlayer $target,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel("game.{$this->game->id}")];
    }

    public function broadcastAs(): string
    {
        return 'hunter.shot';
    }

    /**
     * @return array{
     *   hunter_pseudo: string,   // pseudo du chasseur
     *   target_player_id: int,   // identifiant du joueur abattu
     *   target_pseudo: string,   // pseudo du joueur abattu
     * }
     */
    public function broadcastWith(): array
    {
        return [
            'hunter_pseudo'    => $this->hunter->pseudo,
            'target_player_id' => $this->target->id,
            'target_pseudo'    => $this->target->pseudo,
        ];
    }
}
