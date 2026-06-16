<?php

namespace App\Jobs;

use App\Models\Game;
use App\Services\VoteService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Résout le vote de jour à expiration du timer.
 *
 * Dispatché par PhaseManager::startDay() avec un délai égal au timer day_vote.
 *
 * Double-fire guard : le status doit être exactement 'day' ET le round doit correspondre.
 * 'processing_day' est rejeté car la résolution est déjà en cours ou terminée.
 * Un round décalé indique que la partie a avancé entre le dispatch et l'exécution.
 */
class ProcessDayVote implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param int $gameId Identifiant de la partie.
     * @param int $round  Round de référence (double-fire guard).
     */
    public function __construct(
        public readonly int $gameId,
        public readonly int $round,
    ) {}

    /**
     * Résout le vote de jour via VoteService si les guards passent.
     *
     * Guards d'entrée :
     *   - La partie existe.
     *   - status === 'day' (exactement — 'processing_day' est rejeté).
     *   - round === $this->round.
     *
     * Délègue entièrement à VoteService::resolveDayVote().
     */
    public function handle(VoteService $voteService): void
    {
        $game = Game::find($this->gameId);

        // Double-fire guard : statut ET round doivent correspondre
        if (! $game || $game->status !== 'day' || $game->round !== $this->round) {
            return;
        }

        $voteService->resolveDayVote($game);
    }
}
