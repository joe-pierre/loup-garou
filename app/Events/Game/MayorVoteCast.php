<?php

namespace App\Events\Game;

use App\Models\Game;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Mise à jour du tableau des votes pour l'élection du maire.
 *
 * Canal : PUBLIC — game.{gameId}
 *
 * Déclencheur : VoteController::mayor() après enregistrement d'un vote en base.
 *
 * Anonymisation : le payload ne contient PAS de player_id (qui a voté).
 *   Seuls les totaux par candidat sont exposés. Le vote du maire est secret,
 *   seul le résultat agrégé est rendu public.
 */
class MayorVoteCast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Game $game,
        public readonly array $votes,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel("game.{$this->game->id}")];
    }

    public function broadcastAs(): string
    {
        return 'mayor.vote.cast';
    }

    /**
     * @return array{
     *   votes: array<int, array{
     *     target_player_id: int,  // identifiant du candidat
     *     pseudo: string,         // pseudo du candidat
     *     vote_count: int,        // nombre de votes reçus
     *   }>
     * }
     * ⚠️ Pas de player_id dans le payload — qui a voté n'est pas exposé.
     */
    public function broadcastWith(): array
    {
        return ['votes' => $this->votes];
    }
}
