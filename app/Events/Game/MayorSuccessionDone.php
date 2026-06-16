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
 * Fin de la succession du maire — nouveau maire désigné.
 *
 * Canal : PUBLIC — game.{gameId}
 *
 * Déclencheur : ProcessMayorSuccession::handle() après que le maire sortant
 *   a choisi son successeur ou après expiration du timer (désignation aléatoire).
 */
class MayorSuccessionDone implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Game $game,
        public readonly GamePlayer $newMayor,
        public readonly bool $wasRandom,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel("game.{$this->game->id}")];
    }

    public function broadcastAs(): string
    {
        return 'mayor.succession.done';
    }

    /**
     * @return array{
     *   new_mayor_id: int,     // identifiant du nouveau maire
     *   new_mayor_pseudo: string, // pseudo du nouveau maire
     *   was_random: bool,      // true si le successeur a été désigné aléatoirement (inactivité ou timer)
     * }
     */
    public function broadcastWith(): array
    {
        return [
            'new_mayor_id'     => $this->newMayor->id,
            'new_mayor_pseudo' => $this->newMayor->pseudo,
            'was_random'       => $this->wasRandom,
        ];
    }
}
