<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminUserController extends Controller
{
    public function index(Request $request)
    {
        $wins = GamePlayer::query()
            ->join('games', 'games.id', '=', 'game_players.game_id')
            ->whereNotNull('games.winner_team')
            ->selectRaw('game_players.user_id, SUM('.$this->winCaseSql().') as wins_count')
            ->groupBy('game_players.user_id');

        $rankedRoles = GamePlayer::query()
            ->whereNotNull('role')
            ->selectRaw('user_id, role, ROW_NUMBER() OVER (PARTITION BY user_id ORDER BY COUNT(*) DESC, role ASC) as rn')
            ->groupBy('user_id', 'role');

        $favoriteRoles = DB::query()
            ->fromSub($rankedRoles, 'ranked_roles')
            ->where('rn', 1)
            ->select('user_id', 'role as favorite_role');

        $users = User::query()
            ->select('users.*')
            ->withCount('gamePlayers')
            ->leftJoinSub($wins, 'user_wins', 'user_wins.user_id', '=', 'users.id')
            ->leftJoinSub($favoriteRoles, 'user_favorite_roles', 'user_favorite_roles.user_id', '=', 'users.id')
            ->addSelect([
                DB::raw('COALESCE(user_wins.wins_count, 0) as wins_count'),
                'user_favorite_roles.favorite_role',
            ])
            ->orderByDesc('users.created_at')
            ->paginate(50);

        return view('admin.users.index', compact('users'));
    }

    public function show(Request $request, int $id)
    {
        $user = User::with([
            'gamePlayers.game',
        ])->findOrFail($id);

        $finishedStats = GamePlayer::query()
            ->join('games', 'games.id', '=', 'game_players.game_id')
            ->where('game_players.user_id', $id)
            ->where('games.status', 'finished')
            ->whereNotNull('games.winner_team')
            ->selectRaw('COUNT(*) as total_finished, SUM('.$this->winCaseSql().') as wins')
            ->first();

        $totalFinished = (int) ($finishedStats->total_finished ?? 0);
        $winsCount = (int) ($finishedStats->wins ?? 0);
        $lossesCount = $totalFinished - $winsCount;
        $winRate = $totalFinished > 0 ? round($winsCount / $totalFinished * 100, 1) : 0.0;

        return view('admin.users.show', compact('user', 'winsCount', 'lossesCount', 'winRate', 'totalFinished'));
    }

    /**
     * CASE SQL déterminant si une ligne game_players est une victoire :
     * camp Amoureux via lover_player_id, sinon camp Loups/Village via le rôle.
     * Voir DECISIONS.md "Calcul des victoires par camp (admin stats)".
     */
    private function winCaseSql(): string
    {
        return "CASE
            WHEN games.winner_team = 'lovers' AND game_players.lover_player_id IS NOT NULL THEN 1
            WHEN games.winner_team = 'werewolves' AND game_players.role IN ('werewolf', 'white_wolf') THEN 1
            WHEN games.winner_team = 'villagers' AND game_players.role IN ('villager', 'seer', 'witch', 'hunter') THEN 1
            ELSE 0
        END";
    }
}
