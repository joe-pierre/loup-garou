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
     * @param int  $gameId   Identifiant de la partie.
     * @param int  $round    Round de référence (double-fire guard).
     * @param int  $hunterId Identifiant du joueur Chasseur.
     * @param bool $isMayor  true si le chasseur était également maire — transmis à ProcessHunterAutoAction
     *                       pour déclencher la succession APRÈS le tir (ou le renoncement).
     */
    public function __construct(
        public readonly int $gameId,
        public readonly int $round,
        public readonly int $hunterId,
        public readonly bool $isMayor = false,
    ) {}

    /**
     * Vérifie la victoire, calcule fromNight, vérifie les guards et broadcast HunterTurnStarted.
     *
     * Guards d'entrée :
     *   - La partie existe.
     *   - round === $this->round.
     *   - Victoire amoureux non déjà acquise (SPEC_CUPIDON.md §6, priorité sur le tir
     *     du Chasseur en attente) — vérifiée AVANT toute autre logique, y compris pour
     *     un Chasseur valide sur le point de recevoir son tour.
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

        // Priorité victoire amoureux > tir du Chasseur en attente (SPEC_CUPIDON.md §6) :
        // vérifier AVANT de donner la main au Chasseur, pas seulement dans le fallback
        // ci-dessous — sinon un Chasseur mort déclenche son tour même si la mort qui a
        // créé son hunter_pending a déjà fait tomber l'effectif à 2 amoureux mutuels.
        //
        // $awaitingHunterId = $this->hunterId : à ce stade l'appelant (ProcessNightEnd ou
        // VoteService::dispatchDayVoteConsequences()) a déjà supprimé le hunter_pending de
        // ce Chasseur pour éviter un double dispatch — sans ce paramètre, check() ne verrait
        // plus aucun tir en attente et déclarerait Loups/Village gagnants ICI, avant même le
        // broadcast HunterTurnStarted (voir DECISIONS.md "Victoire Loups déclarée avant
        // résolution du tir du Chasseur").
        if ($winChecker->check($game, awaitingHunterId: $this->hunterId)) {
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
            // Le tir de ce Chasseur est déjà résolu (ou invalide) — le report accordé par
            // awaitingHunterId ci-dessus ne tient plus : re-vérifier la victoire pour de bon
            // avant de transitionner. endNight() refait ce check() lui-même (redondant mais
            // sûr) ; startNight() ne le fait pas (voir PhaseManager::startNight()), d'où ce
            // check() explicite ici pour le cas jour.
            if ($winChecker->check($game)) {
                return;
            }

            $fromNight ? $phaseManager->endNight($game) : $phaseManager->startNight($game);
            return;
        }

        // night_sub_phase n'est consommé par NightResyncService que si le statut est
        // encore une variante de nuit (isNightPhase()) — inoffensif si $fromNight est
        // faux (tir de jour), la lecture côté resync ignore alors ce champ.
        $game->update([
            'night_sub_phase' => 'hunter_turn',
            'phase_deadline'  => now()->addSeconds($game->timer('hunter')),
        ]);

        broadcast(new HunterTurnStarted($game, $hunter));

        ProcessHunterAutoAction::dispatch($this->gameId, $this->round, $this->hunterId, $fromNight, $this->isMayor)
            ->delay(now()->addSeconds($game->timer('hunter')));
    }
}
