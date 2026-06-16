<?php

namespace App\Events\Game;

use App\Models\Game;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Mise à jour de l'état du vote des loups après chaque vote individuel.
 *
 * Canal : PRIVÉ — game.{gameId}.werewolves
 * Seuls les loups voient qui a voté et pour quelle cible dans leur meute.
 *
 * Déclencheur : VoteController::night() après enregistrement du vote d'un loup.
 */
class WerewolvesVoteCast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Game $game,
        public readonly array $wolfVoteState,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("game.{$this->game->id}.werewolves")];
    }

    public function broadcastAs(): string
    {
        return 'werewolves.vote.cast';
    }

    /**
     * @return array{
     *   wolves: array<int, array{
     *     player_id: int,
     *     pseudo: string,
     *     has_voted: bool,
     *     target_player_id: int|null,
     *     target_pseudo: string|null,
     *   }>  // état de vote de chaque loup vivant
     * }
     */
    public function broadcastWith(): array
    {
        return ['wolves' => $this->wolfVoteState];
    }
}
