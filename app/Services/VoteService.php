<?php

namespace App\Services;

use App\Models\Game;
use App\Models\GameAction;
use App\Models\GamePlayer;
use Illuminate\Support\Facades\DB;

class VoteService
{
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
