<?php

namespace App\Services;

use App\Events\Game\GameFinished;
use App\Models\Game;
use App\Notifications\GameFinishedNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Vérifie les conditions de victoire après chaque élimination et termine la partie si nécessaire.
 *
 * Formule de victoire :
 * - Loups gagnent si : nb_loups_vivants >= nb_autres_vivants (ET nb_loups > 0)
 * - Villageois gagnent si : nb_loups_vivants === 0
 *
 * L'annulation pour inactivité (> 50% de joueurs inactifs) est gérée par
 * GameService::cancelGame(), pas par cette classe.
 */
class WinConditionChecker
{
    /**
     * Vérifie si une condition de victoire est atteinte et termine la partie le cas échéant.
     * Broadcaste GameFinished et envoie les notifications push si une victoire est détectée.
     * Guard Symfony Workflow : transition 'finish' vérifiée pour les statuts canoniques 'night'/'day'.
     * Les statuts intermédiaires ('processing_night', 'processing_day') bypassent le guard.
     *
     * @param  Game $game La partie à vérifier (rafraîchie en début de méthode via refresh())
     * @return bool        true si une victoire a été détectée et la partie terminée, false sinon
     */
    public function check(Game $game): bool
    {
        $game->refresh();

        $aliveWerewolves = $game->aliveWerewolvesCount();
        $aliveOthers     = $game->aliveVillagersCount();

        if ($aliveWerewolves > 0 && $aliveWerewolves >= $aliveOthers) {
            $winnerTeam = 'werewolves';
        } elseif ($aliveWerewolves === 0) {
            $winnerTeam = 'villagers';
        } else {
            return false;
        }

        // canTransition() ne connaît que les statuts canoniques du Workflow :
        // 'processing_night'/'processing_day' (Tâches E-H) restent hors de son périmètre et bypassent le guard.
        if (in_array($game->status, ['night', 'day']) && ! $game->canTransition('finish')) {
            Log::warning("Transition 'finish' refusée depuis status={$game->status}");
            return false;
        }

        $game->update([
            'status'      => 'finished',
            'winner_team' => $winnerTeam,
            'finished_at' => now(),
        ]);

        $allPlayers = $game->players()->with('user')->get();
        broadcast(new GameFinished($game, $allPlayers, $winnerTeam));

        try {
            Notification::send(
                $allPlayers->map->user->filter(),
                new GameFinishedNotification($winnerTeam)
            );
        } catch (\Throwable) {}

        return true;
    }
}
