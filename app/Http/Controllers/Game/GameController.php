<?php

namespace App\Http\Controllers\Game;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\GameAction;
use App\Models\GamePlayer;
use App\Services\GameService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GameController extends Controller
{
    public function __construct(private GameService $gameService) {}


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

    public function state(Request $request, string $code): JsonResponse
    {
        $game = Game::where('code', strtoupper($code))->first();

        if (! $game) {
            return response()->json(['success' => false, 'message' => 'Partie introuvable.'], 404);
        }

        $player = $game->players()
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $player) {
            return response()->json(['success' => false, 'message' => 'Tu ne participes pas à cette partie.'], 403);
        }

        $isNight        = $game->status === 'night';
        $deadlineActive = $game->phase_deadline !== null && $game->phase_deadline->isAfter(now());

        $aliveSeerExists = $isNight && $game->players()
            ->where('role', 'seer')
            ->where('is_alive', true)
            ->where('is_inactive', false)
            ->exists();

        $seerCheckDone = $isNight && GameAction::where('game_id', $game->id)
            ->where('type', 'seer_check')
            ->where('round', $game->round)
            ->exists();

        $seerTurnActive       = $isNight && $aliveSeerExists && $deadlineActive && ! $seerCheckDone;
        $werewolvesTurnActive = $isNight && ! $seerTurnActive && $deadlineActive;

        $allies = [];
        if ($player->isWerewolf()) {
            $allies = $game->players()
                ->where('id', '!=', $player->id)
                ->whereIn('role', ['werewolf', 'white_wolf'])
                ->get()
                ->map(fn (GamePlayer $p) => ['id' => $p->id, 'pseudo' => $p->pseudo])
                ->values()
                ->toArray();
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'phase'                   => $game->status,
                'round'                   => $game->round,
                'my_role'                 => $player->role,
                'is_alive'                => (bool) $player->is_alive,
                'is_mayor'                => (bool) $player->is_mayor,
                'phase_remaining_seconds' => $game->phaseRemainingSeconds(),
                'seer_turn_active'        => $seerTurnActive,
                'werewolves_turn_active'  => $werewolvesTurnActive,
                'allies'                  => $allies,
            ],
        ]);
    }

    public function disconnect(Request $request, int $id): JsonResponse
    {
        $game = Game::findOrFail($id);

        $player = $game->players()
            ->where('user_id', $request->user()->id)
            ->first();

        if ($player) {
            $this->gameService->handleDisconnection($player);
        }

        return response()->json(['success' => true]);
    }

    public function reconnect(Request $request, string $code): JsonResponse
    {
        $game = Game::where('code', strtoupper($code))->firstOrFail();

        $player = $game->players()
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $this->gameService->handleReconnection($player);

        return response()->json(['success' => true]);
    }
}
