<?php

namespace App\Jobs;

use App\Events\Game\HunterTurnStarted;
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
 * Démarre le tour du Chasseur après sa mort (nuit ou jour).
 *
 * Dispatché par ProcessNightEnd (mort la nuit, via hunter_pending en DB)
 * ou par VoteService::resolveDayVote() (mort le jour).
 *
 * Le paramètre $fromNight est calculé au moment du dispatch depuis ProcessNightEnd
 * (status IN ['night','processing_night']), car le statut peut avoir changé au moment
 * où le job s'exécute. Ce contexte est transmis à ProcessHunterAutoAction pour
 * qu'il applique la bonne transition (endNight ou startNight) sans ambiguïté.
 */
class ProcessHunterTurn implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param int $gameId   Identifiant de la partie.
     * @param int $round    Round de référence (double-fire guard).
     * @param int $hunterId Identifiant du joueur Chasseur.
     */
    public function __construct(
        public readonly int $gameId,
        public readonly int $round,
        public readonly int $hunterId,
    ) {}

    /**
     * Calcule fromNight, vérifie les guards et broadcast HunterTurnStarted.
     *
     * Guards d'entrée :
     *   - La partie existe.
     *   - round === $this->round.
     *
     * Si le chasseur est invalide (mort, mauvais rôle) ou a déjà tiré :
     *   - Appelle endNight() ou startNight() selon fromNight (Guard #2 RISK_GUARDS).
     *   - return après la transition.
     *
     * Dispatche :
     *   - ProcessHunterAutoAction($gameId, $round, $hunterId, $fromNight)::delay(hunter_timer).
     */
    public function handle(PhaseManager $phaseManager, WinConditionChecker $winChecker): void
    {
        $game = Game::find($this->gameId);

        if (! $game || $game->round !== $this->round) {
            return;
        }

        // Mémorise depuis quelle macro-phase ce tour de chasseur a été déclenché,
        // pour que ProcessHunterAutoAction sache quelle transition appliquer
        // sans risquer de la déclencher deux fois (Guard #2).
        $fromNight = PhaseGuard::isNightOrProcessing($game);

        $hunter = $game->players()->where('id', $this->hunterId)->first();

        $alreadyShot = $game->actions()
            ->where('round', $this->round)
            ->where('type', 'hunter_shot')
            ->where('player_id', $this->hunterId)
            ->exists();

        if (! $hunter || $hunter->is_alive || ! $hunter->isHunter() || $alreadyShot) {
            if (! $winChecker->check($game)) {
                $fromNight ? $phaseManager->endNight($game) : $phaseManager->startNight($game);
            }
            return;
        }

        broadcast(new HunterTurnStarted($game, $hunter));

        ProcessHunterAutoAction::dispatch($this->gameId, $this->round, $this->hunterId, $fromNight)
            ->delay(now()->addSeconds($game->timer('hunter')));
    }
}
