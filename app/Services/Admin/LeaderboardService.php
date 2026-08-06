<?php

namespace App\Services\Admin;

use App\Http\Controllers\Admin\AdminUserController;
use App\Models\GamePlayer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Classements admin (victoires, parties jouées, taux de victoire) — lecture seule.
 * Hors du périmètre app/Services/ métier du jeu (voir CLAUDE.md), dédié à l'admin.
 * Voir DECISIONS.md "Périmètre des classements du leaderboard admin" pour le
 * détail des choix (champ de date, exclusion des parties annulées).
 */
class LeaderboardService
{
    public const MIN_GAMES_FOR_WIN_RATE = 3;

    private const TOP_LIMIT = 10;

    public const PERIODS = ['current_month', 'previous_month', 'all'];

    public function topWins(string $period): Collection
    {
        return $this->baseQuery($period)
            ->selectRaw('users.id, users.name, users.email, SUM('.AdminUserController::winCaseSql().') as wins_count')
            ->groupBy('users.id', 'users.name', 'users.email')
            ->orderByDesc('wins_count')
            ->limit(self::TOP_LIMIT)
            ->get();
    }

    public function topGamesPlayed(string $period): Collection
    {
        return $this->baseQuery($period)
            ->selectRaw('users.id, users.name, users.email, COUNT(*) as games_count')
            ->groupBy('users.id', 'users.name', 'users.email')
            ->orderByDesc('games_count')
            ->limit(self::TOP_LIMIT)
            ->get();
    }

    public function topWinRate(string $period, int $minGames = self::MIN_GAMES_FOR_WIN_RATE): Collection
    {
        $winCaseSql = AdminUserController::winCaseSql();

        return $this->baseQuery($period)
            ->selectRaw(
                'users.id, users.name, users.email, COUNT(*) as games_count, '
                .'SUM('.$winCaseSql.') as wins_count, '
                .'ROUND(SUM('.$winCaseSql.') / COUNT(*) * 100, 1) as win_rate'
            )
            ->groupBy('users.id', 'users.name', 'users.email')
            ->havingRaw('COUNT(*) >= ?', [$minGames])
            ->orderByDesc('win_rate')
            ->limit(self::TOP_LIMIT)
            ->get();
    }

    /**
     * Parties `finished` avec un camp gagnant réel sur la période demandée,
     * jointes aux utilisateurs. Base commune aux trois classements.
     */
    private function baseQuery(string $period): Builder
    {
        $query = GamePlayer::query()
            ->join('games', 'games.id', '=', 'game_players.game_id')
            ->join('users', 'users.id', '=', 'game_players.user_id')
            ->where('games.status', 'finished')
            ->whereNotNull('games.winner_team');

        [$from, $to] = $this->periodRange($period);

        if ($from !== null && $to !== null) {
            $query->whereBetween('games.finished_at', [$from, $to]);
        }

        return $query;
    }

    /**
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function periodRange(string $period): array
    {
        return match ($period) {
            'previous_month' => [
                now()->subMonthNoOverflow()->startOfMonth(),
                now()->subMonthNoOverflow()->endOfMonth(),
            ],
            'all' => [null, null],
            default => [now()->startOfMonth(), now()->endOfMonth()],
        };
    }
}
