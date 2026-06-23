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
 * Visibilité : le vote maire est PUBLIC — l'auteur et la cible sont exposés via leurs pseudos.
 *   ⚠️ Ne PAS ajouter player_id (anti-spoofing) — seuls les pseudos sont autorisés.
 *   Contrairement au vote jour (DayVoteCast), qui reste entièrement anonyme.
 */
class MayorVoteCast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Game $game,
        public readonly array $votes,
        public readonly string $voterPseudo,
        public readonly string $targetPseudo,
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
     *   }>,
     *   voter_pseudo: string,  // pseudo de l'auteur du vote
     *   target_pseudo: string, // pseudo de la cible du vote
     * }
     * ⚠️ Pas de player_id dans le payload — anti-spoofing, seuls les pseudos sont exposés.
     */
    public function broadcastWith(): array
    {
        return [
            'votes'        => $this->votes,
            'voter_pseudo' => $this->voterPseudo,
            'target_pseudo' => $this->targetPseudo,
        ];
    }
}
