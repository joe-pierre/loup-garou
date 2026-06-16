<?php

namespace App\Events\Game;

use App\Models\Game;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Début de la phase de succession du maire — le maire en place vient d'être éliminé.
 *
 * Canal : PUBLIC — game.{gameId}
 *
 * Déclencheur : PhaseManager — appelé lors de l'élimination d'un joueur portant
 *   is_mayor = true (nuit ou jour), avant de continuer la séquence normale.
 */
class MayorSuccessionStarted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Game $game,
        public readonly string $dyingMayorPseudo,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel("game.{$this->game->id}")];
    }

    public function broadcastAs(): string
    {
        return 'mayor.succession.started';
    }

    /**
     * @return array{
     *   timer: int,               // durée accordée au maire pour désigner son successeur en secondes
     *   dying_mayor_pseudo: string, // pseudo du maire éliminé qui doit désigner un successeur
     * }
     */
    public function broadcastWith(): array
    {
        return [
            'timer'              => $this->game->timer('mayor_succession'),
            'dying_mayor_pseudo' => $this->dyingMayorPseudo,
        ];
    }
}
