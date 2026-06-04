<?php

namespace App\Jobs;

use App\Events\Game\MayorSuccessionStarted;
use App\Events\Game\PlayerEliminated;
use App\Models\Game;
use App\Notifications\PlayerKilledNightNotification;
use App\Services\PhaseManager;
use App\Services\VoteService;
use App\Services\WinConditionChecker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessNightActions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $gameId,
        public readonly int $round,
    ) {}

    public function handle(VoteService $voteService, PhaseManager $phaseManager, WinConditionChecker $winChecker): void
    {
        $game = Game::find($this->gameId);

        if (! $game || $game->status !== 'night' || $game->round !== $this->round) {
            return;
        }

        $victim = $voteService->resolveNightVote($game);

        if ($victim) {
            $victim->update(['is_alive' => false]);
            broadcast(new PlayerEliminated($game, $victim, 'night_kill'));

            try {
                $victim->load('user');
                $victim->user->notify(new PlayerKilledNightNotification());
            } catch (\Throwable) {}
        }

        if ($winChecker->check($game)) {
            return;
        }

        if ($victim?->is_mayor) {
            $successionDelay = $victim->is_inactive
                ? 0
                : config('game.timers.mayor_succession', 15);

            broadcast(new MayorSuccessionStarted($game, $victim->pseudo));
            ProcessMayorSuccession::dispatch($game->id, $game->round)
                ->delay(now()->addSeconds($successionDelay));
        }

        $phaseManager->startDay($game, $victim);
    }
}
