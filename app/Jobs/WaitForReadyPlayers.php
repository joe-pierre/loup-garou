<?php

namespace App\Jobs;

use App\Events\Game\MayorElectionStarted;
use App\Models\Game;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Jobs\ProcessMayorElection;

/**
 * Déclenche l'élection du maire si tous les joueurs ne se sont pas déclarés prêts dans le délai.
 *
 * Dispatché par GameService::markReady() (ou startGame()) avec un délai ready_timeout.
 *
 * Si tous les joueurs se déclarent prêts avant expiration, markReady() appelle directement
 * MayorElectionStarted et renseigne phase_deadline. Ce job détecte ce cas via le guard
 * phase_deadline !== null → return sans rien faire (idempotent).
 */
class WaitForReadyPlayers implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param int $gameId Identifiant de la partie.
     */
    public function __construct(public readonly int $gameId) {}

    /**
     * Vérifie les guards et démarre l'élection du maire si nécessaire.
     *
     * Guards d'entrée :
     *   - La partie existe.
     *   - status === 'electing_mayor'.
     *   - phase_deadline === null (sinon MayorElectionStarted déjà déclenché en avance).
     *
     * Dispatche :
     *   - ProcessMayorElection($game->id)::delay(mayor_election).
     */
    public function handle(): void
    {
        $game = Game::find($this->gameId);

        // La partie a été annulée ou a déjà progressé au-delà de la révélation des rôles
        if (! $game || $game->status !== 'electing_mayor') {
            return;
        }

        // phase_deadline non null → MayorElectionStarted déjà broadcasté
        // (déclenché tôt par le ready endpoint si tous les joueurs étaient prêts)
        if ($game->phase_deadline !== null) {
            return;
        }

        $timer    = $game->timer('mayor_election');
        $deadline = now()->addSeconds($timer);

        $game->update(['phase_deadline' => $deadline]);

        broadcast(new MayorElectionStarted($game));
        ProcessMayorElection::dispatch($game->id)->delay(now()->addSeconds($timer));
    }
}
