<?php

namespace App\Http\Controllers\Game;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateGameRequest;
use App\Http\Requests\JoinGameRequest;
use App\Services\GameService;
use Illuminate\Http\JsonResponse;

class LobbyController extends Controller
{
    public function __construct(private GameService $gameService) {}

    public function create(CreateGameRequest $request): JsonResponse
    {
        $game = $this->gameService->createGame(
            $request->user(),
            $request->validated('pseudo'),
            $request->validated('max_players'),
        );

        return response()->json([
            'success' => true,
            'data'    => [
                'game_id' => $game->id,
                'code'    => $game->code,
            ],
        ], 201);
    }

    public function join(JoinGameRequest $request, string $code): JsonResponse
    {
        $player = $this->gameService->joinGame(
            $request->user(),
            strtoupper($code),
            $request->validated('pseudo'),
        );

        return response()->json([
            'success' => true,
            'data'    => [
                'player_id' => $player->id,
                'game_code' => strtoupper($code),
            ],
        ]);
    }
}
