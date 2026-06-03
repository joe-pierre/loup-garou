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
