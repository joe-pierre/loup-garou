<?php

namespace App\Services;

use App\Events\Game\MayorSuccessionStarted;
use App\Events\Game\NoElimination;
use App\Events\Game\PlayerEliminated;
use App\Jobs\ProcessMayorSuccession;
use App\Models\Game;
use App\Models\GameAction;
use App\Models\GamePlayer;
use App\Notifications\PlayerEliminatedDayNotification;
use Illuminate\Support\Facades\DB;

class VoteService
{
    public function __construct(
        private PhaseManager $phaseManager,
        private WinConditionChecker $winConditionChecker,
    ) {}

    public function castMayorVote(GamePlayer $voter, int $targetId): array
    {
        $game = $voter->game;

        if ($game->status !== 'electing_mayor') {
            abort(409, 'La partie n\'est pas en phase d\'élection du maire.');
        }

        return DB::transaction(function () use ($voter, $targetId, $game) {
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

            return $this->getMayorVoteTotals($game);
        });
    }

    /**
     * Résout l'élection du maire : élit le candidat avec le plus de votes,
     * aléatoire en cas d'égalité ou si aucun vote n'a été exprimé.
     * Retourne null si la phase a déjà changé (double-fire guard).
     *
     * @return array{player: GamePlayer, game: Game, was_random: bool}|null
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
                'phase_deadline' => now()->addSeconds(config('game.timers.seer', 30)),
            ]);

            return ['player' => $winner, 'game' => $locked, 'was_random' => $wasRandom];
        });
    }

    public function castNightVote(GamePlayer $wolf, int $targetId): array
    {
        $game = $wolf->game;

        if ($game->status !== 'night') {
            abort(409, 'La partie n\'est pas en phase nuit.');
        }

        if (! $wolf->isWerewolf()) {
            abort(403, 'Seuls les loups peuvent voter la nuit.');
        }

        if (! $wolf->is_alive) {
            abort(403, 'Un joueur éliminé ne peut pas voter.');
        }

        return DB::transaction(function () use ($wolf, $targetId, $game) {
            // Supprimer le vote existant : le loup peut changer de cible jusqu'à expiration
            GameAction::where('game_id', $game->id)
                ->where('player_id', $wolf->id)
                ->where('type', 'night_vote')
                ->where('round', $game->round)
                ->lockForUpdate()
                ->delete();

            GameAction::create([
                'game_id'          => $game->id,
                'player_id'        => $wolf->id,
                'type'             => 'night_vote',
                'weight'           => 1,
                'target_player_id' => $targetId,
                'round'            => $game->round,
                'phase'            => 'night',
            ]);

            return $this->getNightVoteState($game);
        });
    }

    public function resolveDayVote(Game $game): void
    {
        DB::transaction(function () use ($game) {
            $locked = Game::where('id', $game->id)
                ->where('status', 'day')
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return;
            }

            // Agréger les votes pondérés (poids maire = 2 sur day_vote)
            $votes = GameAction::where('game_id', $locked->id)
                ->where('type', 'day_vote')
                ->where('round', $locked->round)
                ->selectRaw('target_player_id, SUM(weight) as vote_weight')
                ->groupBy('target_player_id')
                ->orderByDesc('vote_weight')
                ->get();

            if ($votes->isEmpty()) {
                broadcast(new NoElimination($locked, 'no_vote'));
                $this->phaseManager->startNight($locked);
                return;
            }

            $maxWeight     = $votes->first()->vote_weight;
            $topCandidates = $votes->where('vote_weight', $maxWeight);

            if ($topCandidates->count() > 1) {
                broadcast(new NoElimination($locked, 'equality'));
                $this->phaseManager->startNight($locked);
                return;
            }

            $eliminated = GamePlayer::with('user')->find($topCandidates->first()->target_player_id);
            $eliminated->update(['is_alive' => false]);

            broadcast(new PlayerEliminated($locked, $eliminated, 'day_vote'));

            try {
                $eliminated->user->notify(new PlayerEliminatedDayNotification($eliminated->role));
            } catch (\Throwable) {}


            if ($this->winConditionChecker->check($locked)) {
                return;
            }

            if ($eliminated->is_mayor) {
                // Maire inactif → succession aléatoire immédiate, sinon timer 15s
                $successionDelay = $eliminated->is_inactive
                    ? 0
                    : config('game.timers.mayor_succession', 15);

                broadcast(new MayorSuccessionStarted($locked, $eliminated->pseudo));
                ProcessMayorSuccession::dispatch($locked->id, $locked->round)
                    ->delay(now()->addSeconds($successionDelay));
            } else {
                $this->phaseManager->startNight($locked);
            }
        });
    }

    private function getNightVoteState(Game $game): array
    {
        $aliveWolves = $game->alivePlayers()
            ->whereIn('role', ['werewolf', 'white_wolf'])
            ->get();

        $votedWolfIds = GameAction::where('game_id', $game->id)
            ->where('type', 'night_vote')
            ->where('round', $game->round)
            ->pluck('player_id')
            ->toArray();

        return $aliveWolves->map(fn (GamePlayer $w) => [
            'player_id' => $w->id,
            'pseudo'    => $w->pseudo,
            'has_voted' => in_array($w->id, $votedWolfIds),
        ])->values()->toArray();
    }

    private function getMayorVoteTotals(Game $game): array
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
