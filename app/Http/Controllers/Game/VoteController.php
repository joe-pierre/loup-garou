<?php

namespace App\Http\Controllers\Game;

use App\Events\Game\MayorVoteCast;
use App\Events\Game\WerewolvesVoteCast;
use App\Http\Controllers\Controller;
use App\Http\Requests\MayorVoteRequest;
use App\Http\Requests\NightVoteRequest;
use App\Models\GamePlayer;
use App\Services\VoteService;
use Illuminate\Http\JsonResponse;

class VoteController extends Controller
{
    public function __construct(private VoteService $voteService) {}

    public function mayor(MayorVoteRequest $request, int $id): JsonResponse
    {
        $player = GamePlayer::where('game_id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $votes = $this->voteService->castMayorVote($player, $request->validated('target_player_id'));

        broadcast(new MayorVoteCast($player->game, $votes));

        return response()->json(['success' => true, 'data' => ['votes' => $votes]]);
    }

    public function night(NightVoteRequest $request, int $id): JsonResponse
    {
        $wolf = GamePlayer::where('game_id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $voteState = $this->voteService->castNightVote($wolf, $request->validated('target_player_id'));

        broadcast(new WerewolvesVoteCast($wolf->game, $voteState));

        return response()->json(['success' => true, 'data' => ['wolves' => $voteState]]);
    }
}
