<?php

namespace App\Services;

use App\Events\Game\GameFinished;
use App\Events\Game\PhaseAnnouncement;
use App\Models\Game;
use App\Notifications\GameFinishedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Vérifie les conditions de victoire après chaque élimination et termine la partie si nécessaire.
 *
 * Formule de victoire :
 * - Amoureux gagnent si : nb_vivants === 2 ET les 2 vivants sont mutuellement lover_player_id
 *   l'un de l'autre (vérifié EN PREMIER, quel que soit leur camp d'origine — SPEC_CUPIDON.md §6)
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
     * Lecture + décision + persistance atomiques (lockForUpdate), comme tous les autres
     * points de mutation d'état terminal du jeu (GameService::cancelGame(),
     * VoteService::resolveMayorElection()/resolveDayVoteWinner()) — cette méthode était
     * jusque-là la seule exception, sans lock ni garde contre un statut déjà 'finished',
     * ce qui pouvait la faire courir en concurrence avec cancelGame() (voir DECISIONS.md).
     * Broadcasts et notifications restent HORS transaction (RISK_GUARDS Guard #5).
     *
     * @param  Game $game La partie à vérifier
     * @return bool        true si une victoire a été détectée et la partie terminée, false sinon
     */
    public function check(Game $game): bool
    {
        $decision = DB::transaction(function () use ($game) {
            $locked = Game::where('id', $game->id)->lockForUpdate()->first();

            // Idempotent : partie déjà terminée (victoire déjà persistée par un appel
            // concurrent, ou annulée entre-temps par GameService::cancelGame()) — ne
            // jamais écraser un résultat déjà acquis.
            if (! $locked || $locked->status === 'finished') {
                return null;
            }

            $winnerTeam = null;

            if ($locked->aliveCount() === 2) {
                $aliveTwo = $locked->alivePlayers()->get();
                $first    = $aliveTwo->get(0);
                $second   = $aliveTwo->get(1);

                if ($first && $second
                    && $first->lover_player_id === $second->id
                    && $second->lover_player_id === $first->id
                ) {
                    $winnerTeam = 'lovers';
                }
            }

            if (! $winnerTeam) {
                $aliveWerewolves = $locked->aliveWerewolvesCount();
                $aliveOthers     = $locked->aliveVillagersCount();

                if ($aliveWerewolves > 0 && $aliveWerewolves >= $aliveOthers) {
                    $winnerTeam = 'werewolves';
                } elseif ($aliveWerewolves === 0) {
                    $winnerTeam = 'villagers';
                } else {
                    return null;
                }
            }

            // canTransition() ne connaît que les statuts canoniques du Workflow :
            // 'processing_night'/'processing_day' (Tâches E-H) restent hors de son périmètre et bypassent le guard.
            // PhaseGuard ne couvre pas ce cas : canTransition('finish') n'existe que pour les statuts canoniques Workflow (hors processing)
            if (in_array($locked->status, ['night', 'day']) && ! $locked->canTransition('finish')) {
                Log::warning("Transition 'finish' refusée depuis status={$locked->status}");
                return null;
            }

            $lastAction = $this->buildLastAction($locked);

            $locked->update([
                'status'      => 'finished',
                'winner_team' => $winnerTeam,
                'finished_at' => now(),
            ]);

            return ['game' => $locked, 'winner_team' => $winnerTeam, 'last_action' => $lastAction];
        });

        if (! $decision) {
            return false;
        }

        $finishedGame = $decision['game'];
        $winnerTeam   = $decision['winner_team'];
        $lastAction   = $decision['last_action'];

        $allPlayers = $finishedGame->players()->with('user')->get();

        $announcementMessage = match ($winnerTeam) {
            'lovers'    => 'Les amoureux ont triomphé du destin !',
            'villagers' => 'Le village a triomphé !',
            default     => 'Les loups ont dévoré le village !',
        };
        broadcast(new PhaseAnnouncement($finishedGame->id, 'game_finished', $announcementMessage, 5000));
        broadcast(new GameFinished($finishedGame, $allPlayers, $winnerTeam, $lastAction));

        try {
            Notification::send(
                $allPlayers->map->user->filter(),
                new GameFinishedNotification($winnerTeam)
            );
        } catch (\Throwable) {}

        return true;
    }

    /** @return array<string, mixed> */
    private function buildLastAction(Game $game): array
    {
        $round = $game->round;

        if ($game->isNightPhase()) {
            $recentKill = $game->players()
                ->where('is_alive', false)
                ->latest('id')
                ->first();

            $witchActed = $game->actions()
                ->whereIn('type', ['witch_kill', 'witch_heal'])
                ->where('round', $round)
                ->exists();

            $hunterFired = $game->actions()
                ->where('type', 'hunter_shot')
                ->where('round', $round)
                ->exists();

            return [
                'phase'        => 'night',
                'round'        => $round,
                'killed'       => $recentKill
                    ? ['pseudo' => $recentKill->pseudo, 'role' => $recentKill->role]
                    : null,
                'witch_acted'  => $witchActed,
                'hunter_fired' => $hunterFired,
            ];
        }

        // Contexte jour : trouver le joueur le plus voté ce round
        $dayVotes = $game->actions()
            ->where('type', 'day_vote')
            ->where('round', $round)
            ->get();

        $topVotedId = null;
        $topWeight  = 0;
        if ($dayVotes->isNotEmpty()) {
            $totals     = $dayVotes->groupBy('target_player_id')
                ->map(fn ($g) => $g->sum('weight'));
            $topVotedId = $totals->sortDesc()->keys()->first();
            $topWeight  = (int) $totals[$topVotedId];
        }

        $eliminated = $topVotedId ? $game->players()->find($topVotedId) : null;

        return [
            'phase'      => 'day',
            'round'      => $round,
            'eliminated' => $eliminated
                ? ['pseudo' => $eliminated->pseudo, 'role' => $eliminated->role]
                : null,
            'vote_count' => $topWeight,
        ];
    }
}
