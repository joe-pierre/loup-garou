<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Game;
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

        return view('admin.dashboard', compact(
            'totalGames',
            'activeGames',
            'finishedGames',
            'totalUsers',
            'recentActive',
        ));
    }
}
