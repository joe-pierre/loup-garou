<?php

namespace App\Jobs;

use App\Events\Game\MayorElected;
use App\Events\Game\NightStarted;
use App\Models\Game;
use App\Services\VoteService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Résout l'élection du maire à expiration du timer.
 *
 * Dispatché par WaitForReadyPlayers avec un délai égal au timer mayor_election.
 *
 * En cas d'égalité ou d'absence de votes, le maire est désigné aléatoirement.
 * Après l'élection, dispatche ProcessSeerTurn avec un délai mayor_reveal pour laisser
 * l'UI afficher MayorElected avant le début de la première nuit.
 */
class ProcessMayorElection implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param int $gameId Identifiant de la partie.
     */
    public function __construct(public readonly int $gameId) {}

    /**
     * Résout l'élection via VoteService, broadcast MayorElected + NightStarted.
     *
     * Guards d'entrée :
     *   - La partie existe.
     *   - status === 'electing_mayor'.
     *   - canTransition('start_night') (guard Workflow défensif — statut toujours canonique ici).
     *
     * null retourné par resolveMayorElection() → le statut a changé entre la vérification
     * et la transaction interne → return (idempotent).
     *
     * Dispatche :
     *   - ProcessSeerTurn($gameId)::delay(mayor_reveal).
     */
    public function handle(VoteService $voteService): void
    {
        $game = Game::find($this->gameId);

        if (! $game || $game->status !== 'electing_mayor') {
            return;
        }

        // Double-check Workflow : statut 'electing_mayor' toujours canonique ici.
        if (! $game->canTransition('start_night')) {
            Log::warning("Transition 'start_night' refusée depuis status={$game->status}");
            return;
        }

        $result = $voteService->resolveMayorElection($game);

        // null = status déjà changé entre la vérification et la transaction
        if (! $result) {
            return;
        }

        broadcast(new MayorElected($result['game'], $result['player'], $result['was_random']));
        broadcast(new NightStarted($result['game']));

        // Délai avant le premier tour voyante : laisse le temps à l'UI d'afficher MayorElected.
        ProcessSeerTurn::dispatch($this->gameId)
            ->delay(now()->addSeconds($game->timer('mayor_reveal')));
    }
}
