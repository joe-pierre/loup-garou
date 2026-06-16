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
 * Anonymisation : le payload ne contient PAS de player_id (qui a voté).
 *   Seuls les totaux par cible sont exposés, via GameAction::scopeAnonymized().
 *   Cela empêche d'identifier quel joueur a voté pour quelle cible.
 */
class DayVoteCast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Game $game,
        public readonly array $summary,
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
     *   }>
     * }
     * ⚠️ Pas de player_id dans le payload — qui a voté n'est pas exposé.
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
        ];
    }
}
