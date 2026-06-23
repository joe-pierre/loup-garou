<?php

namespace App\Http\Controllers\Game;

use App\Events\Game\DayVoteCast;
use App\Events\Game\MayorVoteCast;
use App\Events\Game\WerewolvesVoteCast;
use App\Http\Controllers\Controller;
use App\Http\Requests\DayVoteRequest;
use App\Http\Requests\MayorVoteRequest;
use App\Http\Requests\NightVoteRequest;
use App\Models\Game;
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

        $targetId = $request->validated('target_player_id');
        $votes    = $this->voteService->castMayorVote($player, $targetId);

        $targetPseudo = collect($votes)->firstWhere('target_player_id', $targetId)['pseudo'] ?? '';

        broadcast(new MayorVoteCast($player->game, $votes, $player->pseudo, $targetPseudo));

        return response()->json(['success' => true, 'data' => ['votes' => $votes]]);
    }

    public function day(DayVoteRequest $request, int $id): JsonResponse
    {
        $game   = Game::findOrFail($id);
        $player = $game->players()
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $summary = $this->voteService->castDayVote($player, $request->validated('target_player_id'));

        broadcast(new DayVoteCast($game, $summary));

        return response()->json(['success' => true, 'data' => ['votes' => $summary]]);
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