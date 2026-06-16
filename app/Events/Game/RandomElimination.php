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
 * Un joueur a été éliminé aléatoirement (aucun vote reçu lors du vote de jour).
 *
 * Canal : PUBLIC — game.{gameId}
 *
 * Déclencheur : VoteService::resolveDayVote() quand aucun vote valide n'a été
 *   enregistré avant la fin du timer (tous inactifs ou votes annulés).
 */
class RandomElimination implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Game $game,
        public readonly GamePlayer $player,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel("game.{$this->game->id}")];
    }

    public function broadcastAs(): string
    {
        return 'random.elimination';
    }

    /**
     * @return array{
     *   player_id: int,    // identifiant du joueur éliminé aléatoirement
     *   pseudo: string,    // pseudo du joueur éliminé
     *   reason: 'no_votes', // raison fixe : aucun vote valide reçu
     * }
     */
    public function broadcastWith(): array
    {
        return [
            'player_id' => $this->player->id,
            'pseudo'    => $this->player->pseudo,
            'reason'    => 'no_votes',
        ];
    }
}
