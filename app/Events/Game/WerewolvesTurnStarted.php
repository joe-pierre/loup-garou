<?php

namespace App\Events\Game;

use App\Models\Game;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Début du tour de vote des loups-garous.
 *
 * Canal : PRIVÉ — game.{gameId}.werewolves
 * Cet event n'est accessible qu'aux joueurs dont le rôle est werewolf.
 * Il ne doit jamais transiter par le canal public game.{gameId}.
 *
 * Déclencheur : ProcessWerewolvesTurn::handle() après la phase de la voyante.
 *
 * Données sensibles : la liste des cibles éligibles est réservée aux loups.
 */
class WerewolvesTurnStarted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Game $game,
        public readonly array $eligibleTargets,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("game.{$this->game->id}.werewolves")];
    }

    public function broadcastAs(): string
    {
        return 'werewolves.turn.started';
    }

    /**
     * @return array{
     *   timer: int,                // durée du tour des loups en secondes
     *   eligible_targets: array<int, array{id: int, pseudo: string}>,  // joueurs vivants non-loups pouvant être ciblés
     * }
     */
    public function broadcastWith(): array
    {
        return [
            'timer'            => $this->game->timer('werewolves'),
            'eligible_targets' => $this->eligibleTargets,
        ];
    }
}
