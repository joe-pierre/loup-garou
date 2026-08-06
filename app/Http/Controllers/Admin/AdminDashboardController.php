<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Http\Request;

class AdminDashboardController extends Controller
{
    public function index(Request $request)
    {
        $totalGames   = Game::count();
        $activeGames  = Game::active()->count();
        $finishedGames = Game::where('status', 'finished')->count();
        $totalUsers   = User::count();

        $recentActive = Game::active()
            ->withCount('gamePlayers')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $roleDistribution = GamePlayer::query()
            ->whereNotNull('role')
            ->selectRaw('role, count(*) as total')
            ->groupBy('role')
            ->orderByDesc('total')
            ->get();

        // 'cupidon' absent de config('game_ui.role_labels') — fallback local, config
        // hors périmètre admin (voir CLAUDE.md "Ne modifie aucun fichier hors du périmètre admin").
        $roleLabels = array_merge(['cupidon' => 'Cupidon'], config('game_ui.role_labels', []));

        $roleChartLabels = $roleDistribution->pluck('role')
            ->map(fn (string $role) => $roleLabels[$role] ?? $role)
            ->values();
        $roleChartData = $roleDistribution->pluck('total')->values();

        return view('admin.dashboard', compact(
            'totalGames',
            'activeGames',
            'finishedGames',
            'totalUsers',
            'recentActive',
            'roleChartLabels',
            'roleChartData',
        ));
    }
}
