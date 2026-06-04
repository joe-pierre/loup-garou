<?php

namespace App\Http\Controllers\Game;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateGameRequest;
use App\Http\Requests\ExcludePlayerRequest;
use App\Http\Requests\JoinGameRequest;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Services\GameService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

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

    public function exclude(ExcludePlayerRequest $request, int $id, int $playerId): JsonResponse
    {
        $host = GamePlayer::where('game_id', $id)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $target = GamePlayer::where('game_id', $id)
            ->findOrFail($playerId);

        $this->gameService->excludePlayer($host, $target, $request->validated('reason'));

        return response()->json(['success' => true, 'data' => []]);
    }

    public function lobbyState(int $id): JsonResponse
    {
        $game = Game::findOrFail($id);

        GamePlayer::where('game_id', $id)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $players = $game->players()
            ->select(['id', 'pseudo', 'is_host'])
            ->get()
            ->map(fn ($p) => [
                'id'      => $p->id,
                'pseudo'  => $p->pseudo,
                'is_host' => (bool) $p->is_host,
            ])
            ->values()
            ->toArray();

        return response()->json([
            'success' => true,
            'data'    => [
                'players_count'   => count($players),
                'max_players'     => $game->max_players,
                'slots_remaining' => $game->max_players - count($players),
                'players'         => $players,
            ],
        ]);
    }

    public function waitingRoom(string $code): View
    {
        $game = Game::where('code', strtoupper($code))->firstOrFail();

        $currentPlayer = $game->players()
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $players = $game->players()
            ->select(['id', 'pseudo', 'is_host'])
            ->get();

        return view('lobby.waiting-room', [
            'game'          => $game,
            'currentPlayer' => $currentPlayer,
            'players'       => $players,
        ]);
    }
}
