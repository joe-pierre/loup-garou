<?php

namespace App\Jobs;

use App\Models\Game;
use App\Models\GameAction;
use App\Services\PhaseGuard;
use App\Services\PhaseManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Termine la phase nocturne.
 *
 * Dispatché par ProcessNightActions avec un délai couvrant tous les tours restants
 * (witch_timer + mayor_succession + 5s). Également dispatché par ProcessWitchAutoAction
 * en delay(0) une fois le timer sorcière écoulé.
 *
 * ⚠️ Priorité hunter_pending : avant d'appeler endNight(), vérifie si un GameAction
 * de type 'hunter_pending' existe en base. Cet enregistrement est persisté en DB
 * (et non en cache) pour résister aux redémarrages de workers.
 * Voir DECISIONS.md "hunter_pending en GameAction au lieu de Cache volatile".
 *
 * Si hunter_pending présent → supprime l'enregistrement et dispatche
 * ProcessHunterTurn::delay(0) ; c'est ProcessHunterTurn qui appellera endNight() ensuite.
 */
class ProcessNightEnd implements ShouldQueue
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
     * Vérifie hunter_pending puis appelle endNight() ou délègue au Chasseur.
     *
     * Guards d'entrée :
     *   - La partie existe.
     *   - round === $this->round.
     *   - status IN ('night', 'processing_night').
     *   - Si status === 'night' : canTransition('start_day') (guard Workflow défensif).
     *
     * Priorité chasseur :
     *   - Si un hunter_pending existe pour ce round → supprime l'enregistrement,
     *     dispatche ProcessHunterTurn($gameId, $round, $hunterId)::delay(0) et return.
     *
     * Sinon appelle PhaseManager::endNight().
     */
    public function handle(PhaseManager $phaseManager): void
    {
        $game = Game::find($this->gameId);

        if (! $game || $game->round !== $this->round || ! PhaseGuard::isNightOrProcessing($game)) {
            return;
        }

        // Double-check Workflow : 'processing_night' (Tâches E-H) reste hors périmètre et bypasse le guard.
        if ($game->status === 'night' && ! $game->canTransition('start_day')) {
            Log::warning("Transition 'start_day' refusée depuis status={$game->status}");
            return;
        }

        $hunterPending = GameAction::where('game_id', $game->id)
            ->where('type', 'hunter_pending')
            ->where('round', $game->round)
            ->first();

        if ($hunterPending) {
            $hunterPending->delete();
            ProcessHunterTurn::dispatch($game->id, $game->round, $hunterPending->player_id)->delay(0);
            return;
        }

        $phaseManager->endNight($game);
    }
}
