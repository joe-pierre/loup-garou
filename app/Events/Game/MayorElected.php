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
 * Résultat de l'élection du maire.
 *
 * Canal : PUBLIC — game.{gameId}
 *
 * Déclencheur : ProcessMayorElection::handle() via VoteService::resolveMayorElection()
 *   après expiration du timer ou dès que tous les joueurs ont voté.
 *
 * Note : cet event est différé pendant l'overlay d'annonce (PhaseAnnouncement).
 */
class MayorElected implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Game $game,
        public readonly GamePlayer $player,
        public readonly bool $wasRandom,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel("game.{$this->game->id}")];
    }

    public function broadcastAs(): string
    {
        return 'mayor.elected';
    }

    /**
     * @return array{
     *   player_id: int,   // identifiant du nouveau maire
     *   pseudo: string,   // pseudo du nouveau maire
     *   was_random: bool, // true si le maire a été désigné aléatoirement (égalité ou aucun vote)
     * }
     */
    public function broadcastWith(): array
    {
        return [
            'player_id'  => $this->player->id,
            'pseudo'     => $this->player->pseudo,
            'was_random' => $this->wasRandom,
        ];
    }
}
