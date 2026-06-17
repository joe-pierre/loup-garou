<?php

namespace App\Jobs;

use App\Models\Game;
use App\Services\PhaseGuard;
use App\Services\PhaseManager;
use App\Services\WinConditionChecker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Action automatique du Chasseur après expiration du timer de tir.
 *
 * Dispatché par ProcessHunterTurn avec un délai égal au timer chasseur.
 *
 * Les statuts attendus (expectedStatuses) varient selon fromNight :
 *   - fromNight=true  → ['night', 'processing_night']
 *   - fromNight=false → ['day', 'processing_day']
 *
 * Si le statut courant n'est pas dans expectedStatuses, le chasseur a déjà
 * déclenché la transition (tir volontaire traité avant ce job) → return idempotent.
 */
class ProcessHunterAutoAction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param int  $gameId    Identifiant de la partie.
     * @param int  $round     Round de référence (double-fire guard).
     * @param int  $hunterId  Identifiant du joueur Chasseur.
     * @param bool $fromNight true si le chasseur est mort la nuit, false si mort le jour.
     */
    public function __construct(
        public readonly int $gameId,
        public readonly int $round,
        public readonly int $hunterId,
        public readonly bool $fromNight,
    ) {}

    /**
     * Applique le guard idempotent et déclenche la transition de phase suivante.
     *
     * Guards d'entrée :
     *   - La partie existe.
     *   - round === $this->round.
     *   - status IN expectedStatuses (selon fromNight).
     *
     * Le chasseur inactif (pas de tir volontaire) ne déclenche aucune élimination.
     * La transition est déclenchée dans tous les cas si la partie n'est pas terminée :
     *   - fromNight=true  → PhaseManager::endNight().
     *   - fromNight=false → PhaseManager::startNight().
     */
    public function handle(PhaseManager $phaseManager, WinConditionChecker $winChecker): void
    {
        $game = Game::find($this->gameId);

        if (! $game || $game->round !== $this->round) {
            return;
        }

        // Idempotent : si la transition a déjà eu lieu (tir volontaire traité avant ce job),
        // le statut n'est plus celui d'où ce tour de chasseur a été déclenché.
        $isExpected = $this->fromNight
            ? PhaseGuard::isNightOrProcessing($game)
            : PhaseGuard::isDay($game);

        if (! $isExpected) {
            return;
        }

        if ($winChecker->check($game)) {
            return;
        }

        // Chasseur inactif : pas de tir, pas d'élimination supplémentaire — on transitionne simplement.
        if ($this->fromNight) {
            $phaseManager->endNight($game);
        } else {
            $phaseManager->startNight($game);
        }
    }
}
