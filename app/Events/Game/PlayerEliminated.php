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
 * Élimination d'un joueur pendant la phase de jour (vote du village).
 *
 * Canal : PUBLIC — game.{gameId}
 *
 * Déclencheur : VoteService::resolveDayVote() après comptage final des votes de jour.
 *
 * Note : le rôle est révélé ici car l'élimination de jour est publique.
 *   Pour les éliminations nocturnes, le rôle est révélé dans DayStarted.
 */
class PlayerEliminated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Game $game,
        public readonly GamePlayer $player,
        public readonly string $reason,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel("game.{$this->game->id}")];
    }

    public function broadcastAs(): string
    {
        return 'player.eliminated';
    }

    /**
     * @return array{
     *   player_id: int,      // identifiant du joueur éliminé
     *   pseudo: string,      // pseudo du joueur éliminé
     *   google_name: string, // nom Google de l'utilisateur (pour "Jean aka Pseudo")
     *   role: string,        // rôle révélé à l'élimination
     *   reason: string,      // raison de l'élimination (ex: 'day_vote')
     * }
     */
    public function broadcastWith(): array
    {
        return [
            'player_id'   => $this->player->id,
            'pseudo'      => $this->player->pseudo,
            'google_name' => $this->player->user?->name ?? $this->player->pseudo,
            'role'        => $this->player->role,
            'reason'      => $this->reason,
        ];
    }
}
