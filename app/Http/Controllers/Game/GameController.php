<?php

namespace App\Http\Controllers\Game;

use App\Http\Controllers\Controller;
use App\Models\Game;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GameController extends Controller
{
    public function day(Request $request, string $code): View
    {
        $game = Game::where('code', strtoupper($code))->firstOrFail();

        if ($game->status !== 'day') {
            abort(404, 'La partie n\'est pas en phase jour.');
        }

        $player = $game->players()
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $alivePlayers = $game->alivePlayers()->get();

        return view('game.day', compact('game', 'player', 'alivePlayers'));
    }

    public function night(Request $request, string $code): View
    {
        $game = Game::where('code', strtoupper($code))->firstOrFail();

        if ($game->status !== 'night') {
            abort(404, 'La partie n\'est pas en phase nuit.');
        }

        $player = $game->players()
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $alivePlayers = $game->alivePlayers()->get();

        return view('game.night', compact('game', 'player', 'alivePlayers'));
    }
}
