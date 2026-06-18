<?php

namespace App\Services;

use App\Events\Game\MayorSuccessionStarted;
use App\Events\Game\NoElimination;
use App\Events\Game\PlayerEliminated;
use App\Events\Game\RandomElimination;
use App\Jobs\ProcessDayVote;
use App\Jobs\ProcessHunterTurn;
use App\Jobs\ProcessMayorElection;
use App\Jobs\ProcessMayorSuccession;
use App\Jobs\ProcessNightActions;
use App\Models\Game;
use App\Models\GameAction;
use App\Models\GamePlayer;
use App\Notifications\PlayerEliminatedDayNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Gère tous les votes de la partie : élection du maire, vote nuit (loups), vote jour, et leur résolution.
 *
 * Règles critiques :
 * - Vote maire : weight = 1, phase = 'election'
 * - Vote jour : weight = 2 si le votant est maire (lu via lockForUpdate au moment du vote)
 * - Vote nuit : delete+insert atomique — permet le changement de cible jusqu'à expiration du timer
 * - Égalité vote jour → NoElimination broadcasté, personne éliminé
 * - 0 votes jour → élimination aléatoire parmi les joueurs vivants (cf. DECISIONS.md "0 votes jour")
 * - Guard 'processing_day' dans resolveDayVote() : bloque tout double-fire concurrent
 */
class VoteService
{
    public function __construct(
        private PhaseManager $phaseManager,
        private WinConditionChecker $winConditionChecker,
    ) {}

    /**
     * Enregistre le vote d'un joueur pour l'élection du maire.
     * Guard atomique : impossible de voter deux fois dans le même round.
     *
     * @param  GamePlayer $voter    Le joueur qui vote (phase 'electing_mayor' requise)
     * @param  int        $targetId ID du candidat visé (un joueur vivant de la partie)
     * @return array<int, array{target_player_id: int, pseudo: string, vote_count: int}> Totaux de votes actuels
     * @throws \Symfony\Component\HttpKernel\Exception\ConflictHttpException (409) si hors phase élection ou vote déjà exprimé
     */
    public function castMayorVote(GamePlayer $voter, int $targetId): array
    {
        $game = $voter->game;

        if ($game->status !== 'electing_mayor') {
            abort(409, 'La partie n\'est pas en phase d\'élection du maire.');
        }

        $allVoted = false;

        $totals = DB::transaction(function () use ($voter, $targetId, $game, &$allVoted) {
            // lockForUpdate sur les votes existants du joueur : anti-double-vote concurrent
            $alreadyVoted = GameAction::where('game_id', $game->id)
                ->where('player_id', $voter->id)
                ->where('type', 'mayor_vote')
                ->where('round', $game->round)
                ->lockForUpdate()
                ->exists();

            if ($alreadyVoted) {
                abort(409, 'Vous avez déjà voté pour l\'élection du maire.');
            }

            GameAction::create([
                'game_id'          => $game->id,
                'player_id'        => $voter->id,
                'type'             => 'mayor_vote',
                'weight'           => 1,
                'target_player_id' => $targetId,
                'round'            => $game->round,
                'phase'            => 'election',
            ]);

            $alivePlayers = $game->alivePlayers()->count();
            $voterCount   = GameAction::where('game_id', $game->id)
                ->where('type', 'mayor_vote')
                ->where('round', $game->round)
                ->distinct('player_id')
                ->count('player_id');
            $allVoted = $voterCount >= $alivePlayers;

            return $this->getMayorVoteTotals($game);
        });

        // Résolution anticipée : tous les joueurs vivants ont voté → déclencher ProcessMayorElection
        if ($allVoted) {
            ProcessMayorElection::dispatch($game->id);
        }

        return $totals;
    }

    /**
     * Résout l'élection du maire : élit le candidat avec le plus de votes,
     * aléatoire en cas d'égalité ou si aucun vote n'a été exprimé.
     * Guard atomique : retourne null si la phase a déjà changé (double-fire).
     * Guard Symfony Workflow : vérifie canTransition('start_night') avant la mise à jour.
     *
     * @param  Game $game La partie en cours d'élection
     * @return array{player: GamePlayer, game: Game, was_random: bool}|null Résultat, ou null si double-fire
     */
    public function resolveMayorElection(Game $game): ?array
    {
        return DB::transaction(function () use ($game) {
            $locked = Game::where('id', $game->id)
                ->where('status', 'electing_mayor')
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return null;
            }

            if (! $locked->canTransition('start_night')) {
                Log::warning("Transition 'start_night' refusée depuis status={$locked->status}");
                return null;
            }

            $votes = GameAction::where('game_id', $locked->id)
                ->where('type', 'mayor_vote')
                ->where('round', $locked->round)
                ->selectRaw('target_player_id, COUNT(*) as vote_count')
                ->groupBy('target_player_id')
                ->orderByDesc('vote_count')
                ->get();

            $wasRandom = false;

            if ($votes->isEmpty()) {
                $winner    = $locked->alivePlayers()->inRandomOrder()->first();
                $wasRandom = true;
            } else {
                $maxVotes      = $votes->first()->vote_count;
                $topCandidates = $votes->where('vote_count', $maxVotes);

                if ($topCandidates->count() > 1) {
                    $wasRandom  = true;
                    $winnerId   = $topCandidates->random()->target_player_id;
                } else {
                    $winnerId = $topCandidates->first()->target_player_id;
                }

                $winner = GamePlayer::find($winnerId);
            }

            $winner->update(['is_mayor' => true]);

            $locked->update([
                'status'         => 'night',
                'round'          => 1,
                'phase_deadline' => now()->addSeconds($locked->timer('seer')),
            ]);

            return ['player' => $winner, 'game' => $locked, 'was_random' => $wasRandom];
        });
    }

    /**
     * Calcule la victime du vote nocturne des loups pour le round courant.
     * En cas d'égalité entre plusieurs cibles, la victime est choisie aléatoirement.
     * Retourne null si aucun vote n'a été exprimé (les loups sont en égalité totale).
     *
     * @param  Game         $game La partie en phase nuit
     * @return GamePlayer|null     Le joueur ciblé par les loups, ou null si aucun vote
     */
    public function resolveNightVote(Game $game): ?GamePlayer
    {
        $votes = GameAction::where('game_id', $game->id)
            ->where('type', 'night_vote')
            ->where('round', $game->round)
            ->selectRaw('target_player_id, COUNT(*) as vote_count')
            ->groupBy('target_player_id')
            ->orderByDesc('vote_count')
            ->get();

        if ($votes->isEmpty()) {
            return null;
        }

        $maxVotes      = $votes->first()->vote_count;
        $topCandidates = $votes->where('vote_count', $maxVotes);

        $winnerId = $topCandidates->count() > 1
            ? $topCandidates->random()->target_player_id
            : $topCandidates->first()->target_player_id;

        return GamePlayer::find($winnerId);
    }

    /**
     * Enregistre ou remplace le vote nocturne d'un loup (delete+insert atomique).
     * Un loup peut changer de cible jusqu'à expiration du timer.
     * Déclenche ProcessNightActions immédiatement si tous les loups vivants ont voté.
     *
     * @param  GamePlayer $wolf     Le loup votant (rôle 'werewolf'/'white_wolf', vivant, phases 'night'/'wolves_turn')
     * @param  int        $targetId ID du joueur cible (validé par NightVoteRequest — pas un loup vivant)
     * @return array<int, array{player_id: int, pseudo: string, has_voted: bool, target_player_id: int|null, target_pseudo: string|null}> État de vote de tous les loups
     * @throws \Symfony\Component\HttpKernel\Exception\ConflictHttpException     (409) si hors phase nuit
     * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException (403) si $wolf n'est pas loup ou est mort
     */
    public function castNightVote(GamePlayer $wolf, int $targetId): array
    {
        $game = $wolf->game;

        // PhaseGuard ne couvre pas ce cas : 'processing_night' exclu volontairement (vote clos)
        if (! in_array($game->status, ['night', 'wolves_turn'])) {
            abort(409, 'La partie n\'est pas en phase nuit.');
        }

        if (! $wolf->isWerewolf()) {
            abort(403, 'Seuls les loups peuvent voter la nuit.');
        }

        if (! $wolf->is_alive) {
            abort(403, 'Un joueur éliminé ne peut pas voter.');
        }

        $allVoted = false;
        $round    = $game->round;

        $state = DB::transaction(function () use ($wolf, $targetId, $game, &$allVoted, $round) {
            // Supprimer le vote existant : le loup peut changer de cible jusqu'à expiration
            GameAction::where('game_id', $game->id)
                ->where('player_id', $wolf->id)
                ->where('type', 'night_vote')
                ->where('round', $round)
                ->lockForUpdate()
                ->delete();

            GameAction::create([
                'game_id'          => $game->id,
                'player_id'        => $wolf->id,
                'type'             => 'night_vote',
                'weight'           => 1,
                'target_player_id' => $targetId,
                'round'            => $round,
                'phase'            => 'night',
            ]);

            // Vérifier si tous les loups vivants ont voté
            $aliveWolfIds = $game->alivePlayers()
                ->whereIn('role', ['werewolf', 'white_wolf'])
                ->pluck('id');

            $votedCount = GameAction::where('game_id', $game->id)
                ->where('type', 'night_vote')
                ->where('round', $round)
                ->whereIn('player_id', $aliveWolfIds)
                ->count();

            $allVoted = $votedCount >= $aliveWolfIds->count();

            return $this->getNightVoteState($game);
        });

        // Délai de 1s pour absorber les votes quasi-simultanés avant que ProcessNightActions
        // ne passe le statut à 'processing_night' et bloque les votes tardifs (race condition réseau).
        if ($allVoted) {
            \App\Jobs\ProcessNightActions::dispatch($game->id, $round)
                ->delay(now()->addSecond());
        }

        return $state;
    }

    /**
     * Résout le vote jour : élimine le joueur avec le plus de poids de votes.
     * Guard atomique 'processing_day' : passe le statut de 'day' à 'processing_day' dès l'entrée
     * en transaction pour bloquer tout double-fire concurrent.
     *
     * Cas d'égalité → NoElimination broadcasté, personne éliminé, la nuit commence.
     * Cas 0 votes → élimination aléatoire parmi les vivants.
     * Si le maire est éliminé → ProcessMayorSuccession dispatché.
     * Si le chasseur est éliminé → ProcessHunterTurn dispatché via hunter_pending en DB.
     * WinConditionChecker appelé après chaque élimination.
     *
     * @param  Game $game La partie en phase jour
     * @return void
     */
    public function resolveDayVote(Game $game): void
    {
        $eliminated   = null;
        $noElimReason = null;
        $randomVictim = null;

        DB::transaction(function () use ($game, &$eliminated, &$noElimReason, &$randomVictim) {
            $locked = Game::where('id', $game->id)
                ->where('status', 'day')
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return;
            }

            if (! $locked->canTransition('continue_night')) {
                Log::warning("Transition 'continue_night' refusée depuis status={$locked->status}");
                return;
            }

            $locked->update(['status' => 'processing_day']);

            $votes = GameAction::where('game_id', $locked->id)
                ->where('type', 'day_vote')
                ->where('round', $locked->round)
                ->selectRaw('target_player_id, SUM(weight) as vote_weight')
                ->groupBy('target_player_id')
                ->orderByDesc('vote_weight')
                ->get();

            if ($votes->isEmpty()) {
                $victim = $locked->alivePlayers()->inRandomOrder()->first();
                if ($victim) {
                    $victim->update(['is_alive' => false]);
                    $randomVictim = $victim;

                    if ($victim->isHunter()) {
                        GameAction::create([
                            'game_id'   => $locked->id,
                            'player_id' => $victim->id,
                            'type'      => 'hunter_pending',
                            'round'     => $locked->round,
                            'phase'     => 'day',
                        ]);
                    }
                }
                return;
            }

            $maxWeight     = $votes->first()->vote_weight;
            $topCandidates = $votes->where('vote_weight', $maxWeight);

            if ($topCandidates->count() > 1) {
                $noElimReason = 'equality';
                return;
            }

            $elim = GamePlayer::with('user')->find($topCandidates->first()->target_player_id);
            $elim->update(['is_alive' => false]);
            $eliminated = $elim;

            if ($elim->isHunter()) {
                GameAction::create([
                    'game_id'   => $locked->id,
                    'player_id' => $elim->id,
                    'type'      => 'hunter_pending',
                    'round'     => $locked->round,
                    'phase'     => 'day',
                ]);
            }
        });

        // Tout ce qui suit est HORS transaction

        if ($noElimReason) {
            broadcast(new NoElimination($game, $noElimReason));
            $this->phaseManager->startNight($game);
            return;
        }

        if ($randomVictim) {
            broadcast(new RandomElimination($game, $randomVictim));
            if ($this->winConditionChecker->check($game)) {
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
            $this->phaseManager->startNight($game);
            return;
        }

        if (! $eliminated) {
            return;
        }

        broadcast(new PlayerEliminated($game, $eliminated, 'day_vote'));

        try {
            $eliminated->user->notify(new PlayerEliminatedDayNotification($eliminated->role));
        } catch (\Throwable) {}

        if ($this->winConditionChecker->check($game)) {
            return;
        }

        // Le flag is_mayor a pu être modifié par une transaction concurrente
        // (ex. ProcessMayorSuccession) entre le chargement et ce point
        $eliminated->refresh();

        if ($eliminated->is_mayor) {
            $successionDelay = $eliminated->is_inactive
                ? 0
                : $game->timer('mayor_succession');

            broadcast(new MayorSuccessionStarted($game, $eliminated->pseudo));
            ProcessMayorSuccession::dispatch($game->id, $game->round)
                ->delay(now()->addSeconds($successionDelay));
        } elseif ($hunterPending = GameAction::where('game_id', $game->id)->where('type', 'hunter_pending')->where('round', $game->round)->first()) {
            $hunterPending->delete();
            ProcessHunterTurn::dispatch($game->id, $game->round, $hunterPending->player_id)->delay(0);
        } else {
            $this->phaseManager->startNight($game);
        }
    }

    /**
     * Enregistre le vote jour d'un joueur.
     * Le maire vote avec un poids de 2 (lu via lockForUpdate au moment du vote).
     * Déclenche ProcessDayVote immédiatement si tous les joueurs vivants ont voté.
     *
     * @param  GamePlayer $voter    Le joueur qui vote (vivant, phase 'day' requise)
     * @param  int        $targetId ID du joueur cible (vivant, différent du votant, validé par DayVoteRequest)
     * @return array<int, float|int> Résumé courant [target_player_id => poids_total]
     * @throws \Symfony\Component\HttpKernel\Exception\ConflictHttpException                 (409) si hors phase jour ou vote déjà exprimé
     * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException             (403) si le joueur est mort
     * @throws \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException (422) si vote contre soi-même
     */
    public function castDayVote(GamePlayer $voter, int $targetId): array
    {
        if ($voter->game->status !== 'day') {
            abort(409, 'La partie n\'est pas en phase jour.');
        }

        if (! $voter->is_alive) {
            abort(403, 'Vous êtes mort.');
        }

        $allVoted = false;
        $round    = $voter->game->round;
        $gameId   = $voter->game_id;

        DB::transaction(function () use ($voter, $targetId, &$allVoted, $round) {
            $alreadyVoted = GameAction::where('game_id', $voter->game_id)
                ->where('player_id', $voter->id)
                ->where('type', 'day_vote')
                ->where('round', $round)
                ->lockForUpdate()
                ->exists();

            if ($alreadyVoted) {
                abort(409, 'Vous avez déjà voté ce round.');
            }

            $target = GamePlayer::where('id', $targetId)
                ->where('game_id', $voter->game_id)
                ->where('is_alive', true)
                ->firstOrFail();

            if ($target->id === $voter->id) {
                abort(422, 'Vous ne pouvez pas voter contre vous-même.');
            }

            $freshVoter = GamePlayer::lockForUpdate()->find($voter->id);
            $weight     = $freshVoter->is_mayor ? 2 : 1;

            GameAction::create([
                'game_id'          => $voter->game_id,
                'player_id'        => $voter->id,
                'type'             => 'day_vote',
                'weight'           => $weight,
                'target_player_id' => $target->id,
                'round'            => $round,
                'phase'            => 'day',
            ]);

            // Vérifier si tous les joueurs vivants ont voté
            $alivePlayerIds = GamePlayer::where('game_id', $voter->game_id)
                ->where('is_alive', true)
                ->pluck('id');

            $votedCount = GameAction::where('game_id', $voter->game_id)
                ->where('type', 'day_vote')
                ->where('round', $round)
                ->whereIn('player_id', $alivePlayerIds)
                ->count();

            $allVoted = $votedCount >= $alivePlayerIds->count();
        });

        // Résolution anticipée : tous les vivants ont voté → déclencher ProcessDayVote immédiatement
        if ($allVoted) {
            \App\Jobs\ProcessDayVote::dispatch($gameId, $round);
        }

        return $this->getDayVoteSummary($voter->game);
    }

    /**
     * Retourne le récapitulatif des votes jour du round courant (poids total par cible).
     *
     * @param  Game $game La partie concernée
     * @return array<int, float|int> [target_player_id => poids_total_votes]
     */
    private function getDayVoteSummary(Game $game): array
    {
        return GameAction::where('game_id', $game->id)
            ->where('type', 'day_vote')
            ->where('round', $game->round)
            ->get()
            ->groupBy('target_player_id')
            ->map(fn ($group) => $group->sum('weight'))
            ->toArray();
    }

    /**
     * Retourne l'état des votes nocturnes de tous les loups vivants.
     *
     * @param  Game $game La partie concernée
     * @return array<int, array{player_id: int, pseudo: string, has_voted: bool, target_player_id: int|null, target_pseudo: string|null}>
     */
    private function getNightVoteState(Game $game): array
    {
        $aliveWolves = $game->alivePlayers()
            ->whereIn('role', ['werewolf', 'white_wolf'])
            ->get();

        $votes = GameAction::where('game_id', $game->id)
            ->where('type', 'night_vote')
            ->where('round', $game->round)
            ->get()
            ->keyBy('player_id');

        $targetIds = $votes->pluck('target_player_id')->filter()->unique();
        $targets   = GamePlayer::whereIn('id', $targetIds)
            ->pluck('pseudo', 'id');

        return $aliveWolves->map(fn (GamePlayer $w) => [
            'player_id'        => $w->id,
            'pseudo'           => $w->pseudo,
            'has_voted'        => $votes->has($w->id),
            'target_player_id' => $votes->get($w->id)?->target_player_id,
            'target_pseudo'    => $votes->has($w->id)
                ? ($targets[$votes[$w->id]->target_player_id] ?? null)
                : null,
        ])->values()->toArray();
    }

    /**
     * Retourne les totaux de votes pour l'élection du maire (round courant), avec pseudo des candidats.
     *
     * @param  Game $game La partie en cours d'élection
     * @return array<int, array{target_player_id: int, pseudo: string, vote_count: int}>
     */
    public function getMayorVoteTotals(Game $game): array
    {
        return GameAction::where('game_actions.game_id', $game->id)
            ->where('game_actions.type', 'mayor_vote')
            ->where('game_actions.round', $game->round)
            ->join('game_players', 'game_actions.target_player_id', '=', 'game_players.id')
            ->selectRaw('game_actions.target_player_id, game_players.pseudo, COUNT(*) as vote_count')
            ->groupBy('game_actions.target_player_id', 'game_players.pseudo')
            ->get()
            ->map(fn ($row) => [
                'target_player_id' => $row->target_player_id,
                'pseudo'           => $row->pseudo,
                'vote_count'       => (int) $row->vote_count,
            ])
            ->values()
            ->toArray();
    }
}