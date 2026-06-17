<?php

namespace App\Http\Controllers\Game;

use App\Events\Game\DayVoteCast;
use App\Events\Game\MayorVoteCast;
use App\Events\Game\WerewolvesVoteCast;
use App\Http\Controllers\Controller;
use App\Http\Requests\DayVoteRequest;
use App\Http\Requests\MayorVoteRequest;
use App\Http\Requests\NightVoteRequest;
use App\Jobs\ProcessDayVote;
use App\Jobs\ProcessMayorElection;
use App\Jobs\ProcessNightActions;
use App\Models\Game;
use App\Models\GameAction;
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

        // Résolution immédiate si tous les joueurs vivants ont voté
        $this->_checkAllMayorVotesCast($player->game);

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

        // Résolution immédiate si tous les joueurs vivants ont voté
        $this->_checkAllDayVotesCast($game);

        return response()->json(['success' => true, 'data' => ['votes' => $summary]]);
    }

    public function night(NightVoteRequest $request, int $id): JsonResponse
    {
        $wolf = GamePlayer::where('game_id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $voteState = $this->voteService->castNightVote($wolf, $request->validated('target_player_id'));

        broadcast(new WerewolvesVoteCast($wolf->game, $voteState));

        // Résolution immédiate si tous les loups vivants ont voté
        $this->_checkAllNightVotesCast($wolf->game);

        return response()->json(['success' => true, 'data' => ['wolves' => $voteState]]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Résolution immédiate élection maire si tous les vivants ont voté
    // ─────────────────────────────────────────────────────────────────────────
    private function _checkAllMayorVotesCast(Game $game): void
    {
        $game->refresh();

        if ($game->status !== 'electing_mayor') {
            return;
        }

        $alivePlayers = $game->alivePlayers()->count();

        $voterCount = GameAction::where('game_id', $game->id)
            ->where('type', 'mayor_vote')
            ->where('round', $game->round)
            ->distinct('player_id')
            ->count('player_id');

        if ($voterCount >= $alivePlayers) {
            // ProcessMayorElection a un guard status='electing_mayor' → pas de double-fire
            ProcessMayorElection::dispatch($game->id);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Résolution immédiate vote jour si tous les vivants ont voté
    // ─────────────────────────────────────────────────────────────────────────
    private function _checkAllDayVotesCast(Game $game): void
    {
        $game->refresh();

        if ($game->status !== 'day') {
            return;
        }

        $alivePlayers = $game->alivePlayers()->count();

        $voterCount = GameAction::where('game_id', $game->id)
            ->where('type', 'day_vote')
            ->where('round', $game->round)
            ->distinct('player_id')
            ->count('player_id');

        if ($voterCount >= $alivePlayers) {
            ProcessDayVote::dispatch($game->id, $game->round);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Résolution immédiate vote nuit si tous les loups vivants ont voté
    // ─────────────────────────────────────────────────────────────────────────
    private function _checkAllNightVotesCast(Game $game): void
    {
        $game->refresh();

        // PhaseGuard ne couvre pas ce cas : 'processing_night' exclu volontairement (vote clos)
        if (! in_array($game->status, ['night', 'wolves_turn'])) {
            return;
        }

        $aliveWolves = $game->alivePlayers()
            ->whereIn('role', ['werewolf', 'white_wolf'])
            ->count();

        $votedWolves = GameAction::where('game_id', $game->id)
            ->where('type', 'night_vote')
            ->where('round', $game->round)
            ->distinct('player_id')
            ->count('player_id');

        if ($votedWolves >= $aliveWolves) {
            ProcessNightActions::dispatch($game->id, $game->round);
        }
    }
}