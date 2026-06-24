<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Game;
use Illuminate\Http\Request;

class AdminGameController extends Controller
{
    public function index(Request $request)
    {
        $query = Game::withCount('gamePlayers')->orderByDesc('created_at');

        $statusFilter    = $request->get('status');
        $allowedStatuses = ['waiting', 'finished', 'active'];

        if ($statusFilter === 'active') {
            $query->whereNotIn('status', ['finished']);
        } elseif (in_array($statusFilter, ['waiting', 'finished'])) {
            $query->where('status', $statusFilter);
        }

        $games = $query->paginate(25);

        return view('admin.games.index', compact('games', 'statusFilter'));
    }

    public function show(Request $request, int $id)
    {
        return view('admin.games.show');
    }
}
