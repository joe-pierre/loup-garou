<?php

namespace App\Events\Game;

use App\Models\Game;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Mise à jour du tableau des votes du jour après chaque vote individuel.
 *
 * Canal : PUBLIC — game.{gameId}
 *
 * Déclencheur : VoteController::day() après enregistrement d'un vote en base.
 *
 * Visibilité : le vote jour est PUBLIC — l'auteur et la cible sont exposés via leurs pseudos.
 *   ⚠️ Ne PAS ajouter player_id (anti-spoofing) — seuls les pseudos sont autorisés.
 *   Symétrique au vote maire (MayorVoteCast) — décision assumée le 2026-07-23, voir SPEC.md
 *   §"Visibilité des votes" (le vote jour était anonyme par design jusqu'alors).
 */
class DayVoteCast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Game $game,
        public readonly array $summary,
        public readonly string $voterPseudo,
        public readonly string $targetPseudo,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel("game.{$this->game->id}")];
    }

    public function broadcastAs(): string
    {
        return 'day.vote.cast';
    }

    /**
     * @return array{
     *   votes: array<int, array{
     *     target_player_id: int,  // identifiant de la cible
     *     total_weight: int,      // poids total des votes reçus (2 si le maire a voté pour cette cible)
     *   }>,
     *   voter_pseudo: string,  // pseudo de l'auteur du vote
     *   target_pseudo: string, // pseudo de la cible du vote
     * }
     * ⚠️ Pas de player_id dans le payload — anti-spoofing, seuls les pseudos sont exposés.
     */
    public function broadcastWith(): array
    {
        return [
            'votes' => collect($this->summary)
                ->map(fn ($totalWeight, $targetPlayerId) => [
                    'target_player_id' => $targetPlayerId,
                    'total_weight'     => $totalWeight,
                ])
                ->values()
                ->toArray(),
            'voter_pseudo'  => $this->voterPseudo,
            'target_pseudo' => $this->targetPseudo,
        ];
    }
}
