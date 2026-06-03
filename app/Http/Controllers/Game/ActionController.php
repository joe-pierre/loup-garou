<?php

namespace App\Http\Controllers\Game;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Services\GameService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ActionController extends Controller
{
    public function __construct(private GameService $gameService) {}

    public function ready(Request $request, int $id): JsonResponse
    {
        $player = GamePlayer::where('game_id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $this->gameService->markReady($player);

        return response()->json(['success' => true, 'data' => []]);
    }

    public function roleReveal(Request $request, string $code): View
    {
        $game = Game::where('code', strtoupper($code))->firstOrFail();

        if (! in_array($game->status, ['electing_mayor', 'night', 'day'])) {
            abort(404, 'Rôles non encore distribués.');
        }

        $player = $game->players()
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $allies = [];
        if ($player->isWerewolf()) {
            $allies = $game->players()
                ->where('id', '!=', $player->id)
                ->get()
                ->filter(fn (GamePlayer $p) => $p->isWerewolf())
                ->map(fn (GamePlayer $p) => ['id' => $p->id, 'pseudo' => $p->pseudo])
                ->values()
                ->toArray();
        }

        $nbReady = $game->players()->where('is_ready', true)->count();
        $total   = $game->players()->count();

        return view('game.role-reveal', compact('game', 'player', 'allies', 'nbReady', 'total'));
    }
}
