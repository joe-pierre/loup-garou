<?php

namespace App\Jobs;

use App\Events\Game\SeerTurnStarted;
use App\Models\Game;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Démarre le tour de la voyante.
 *
 * Dispatché par ProcessMayorElection après un délai mayor_reveal, et par
 * PhaseManager::startNight() pour les rondes suivantes.
 * Si la voyante est morte, absente ou inactive → dispatch immédiat de ProcessWerewolvesTurn.
 * Sinon :
 *   - Broadcast SeerTurnStarted sur le canal privé de la voyante.
 *   - Dispatche ProcessSeerAutoAction à ceil(seer/2) secondes (auto-inspection à mi-timer).
 *   - Dispatche ProcessWerewolvesTurn à (seer+2) secondes (délai fixe post-timer).
 */
class ProcessSeerTurn implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param int $gameId Identifiant de la partie.
     * @param int $round  Round de référence (guard anti-double-fire entre deux nuits).
     */
    public function __construct(public readonly int $gameId, public readonly int $round) {}

    /**
     * Vérifie les guards, broadcast SeerTurnStarted et dispatche les jobs suivants.
     *
     * Guards d'entrée :
     *   - La partie existe.
     *   - status === 'night'.
     *   - round === $this->round (évite qu'un job stale d'une nuit précédente s'exécute).
     *
     * Dispatche :
     *   - ProcessWerewolvesTurn(delay=0) si voyante morte/absente/inactive.
     *   - ProcessSeerAutoAction($gameId, $seerId, $round)::delay(ceil(seer/2)).
     *   - ProcessWerewolvesTurn($gameId, $round)::delay(seer+2).
     */
    public function handle(): void
    {
        $game = Game::find($this->gameId);

        if (! $game || $game->status !== 'night' || $game->round !== $this->round) {
            return;
        }

        $seer = $game->players()->where('role', 'seer')->where('is_alive', true)->first();

        // Voyante morte/absente/inactive → loups immédiatement
        if (! $seer || $seer->is_inactive) {
            ProcessWerewolvesTurn::dispatch($this->gameId, $this->round);
            return;
        }

        $seerTimer = $game->timer('seer');
        $halfTimer = (int) ceil($seerTimer / 2);

        $game->update([
            'phase_deadline'  => now()->addSeconds($seerTimer),
            'night_sub_phase' => 'seer_turn',
        ]);

        broadcast(new SeerTurnStarted($game, $seer));

        // À mi-timer : auto-inspect si la voyante n'a pas agi
        ProcessSeerAutoAction::dispatch($this->gameId, $seer->id, $game->round)
            ->delay(now()->addSeconds($halfTimer));

        // Après timer complet + 5s (pour laisser la voyante voir le résultat)
        // → loups démarrent
        ProcessWerewolvesTurn::dispatch($this->gameId, $this->round)
            ->delay(now()->addSeconds($seerTimer + 2));
    }
}