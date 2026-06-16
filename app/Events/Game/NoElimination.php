<?php

namespace App\Events\Game;

use App\Models\Game;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Aucun joueur n'a été éliminé lors du vote de jour (égalité).
 *
 * Canal : PUBLIC — game.{gameId}
 *
 * Déclencheur : VoteService::resolveDayVote() en cas d'égalité parfaite des votes.
 *   La règle métier stipule qu'en cas d'égalité, personne n'est éliminé.
 */
class NoElimination implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Game $game,
        public readonly string $reason,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel("game.{$this->game->id}")];
    }

    public function broadcastAs(): string
    {
        return 'no.elimination';
    }

    /**
     * @return array{
     *   reason: string,  // raison de l'absence d'élimination (ex: 'equality')
     * }
     */
    public function broadcastWith(): array
    {
        return ['reason' => $this->reason];
    }
}
