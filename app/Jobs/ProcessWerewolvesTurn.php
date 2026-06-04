<?php

namespace App\Jobs;

use App\Events\Game\WerewolvesTurnStarted;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessWerewolvesTurn implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $gameId) {}

    public function handle(): void
    {
        $game = Game::find($this->gameId);

        if (! $game || $game->status !== 'night') {
            return;
        }

        $timer = config('game.timers.werewolves', 30);
        $game->update(['phase_deadline' => now()->addSeconds($timer)]);

        $eligibleTargets = $game->alivePlayers()
            ->whereNotIn('role', ['werewolf', 'white_wolf'])
            ->get()
            ->map(fn (GamePlayer $p) => ['id' => $p->id, 'pseudo' => $p->pseudo])
            ->values()
            ->toArray();

        broadcast(new WerewolvesTurnStarted($game, $eligibleTargets));

        ProcessNightActions::dispatch($this->gameId, $game->round)
            ->delay(now()->addSeconds($timer));
    }
}
