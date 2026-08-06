<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\LeaderboardService;
use Illuminate\Http\Request;

class AdminLeaderboardController extends Controller
{
    public function index(Request $request, LeaderboardService $leaderboardService)
    {
        $period = $request->get('period', 'current_month');

        if (!in_array($period, LeaderboardService::PERIODS, true)) {
            $period = 'current_month';
        }

        $topWins = $leaderboardService->topWins($period);
        $topGamesPlayed = $leaderboardService->topGamesPlayed($period);
        $topWinRate = $leaderboardService->topWinRate($period);
        $minGamesForWinRate = LeaderboardService::MIN_GAMES_FOR_WIN_RATE;

        return view('admin.leaderboard', compact(
            'period',
            'topWins',
            'topGamesPlayed',
            'topWinRate',
            'minGamesForWinRate',
        ));
    }
}
