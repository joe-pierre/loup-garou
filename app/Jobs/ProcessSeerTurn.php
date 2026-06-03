<?php

namespace App\Jobs;

use App\Events\Game\SeerTurnStarted;
use App\Models\Game;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessSeerTurn implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $gameId) {}

    public function handle(): void
    {
        $game = Game::find($this->gameId);

        if (! $game || $game->status !== 'night') {
            return;
        }

        $seer = $game->players()->where('role', 'seer')->first();

        // Voyante morte ou absente → skip immédiat vers les loups
        if (! $seer || ! $seer->is_alive) {
            ProcessWerewolvesTurn::dispatch($this->gameId);
            return;
        }

        // Voyante inactive → skip immédiat sans consommer le timer de 30s
        if ($seer->is_inactive) {
            ProcessWerewolvesTurn::dispatch($this->gameId);
            return;
        }

        $timer = config('game.timers.seer', 30);
        $game->update(['phase_deadline' => now()->addSeconds($timer)]);

        broadcast(new SeerTurnStarted($game, $seer));

        ProcessWerewolvesTurn::dispatch($this->gameId)
            ->delay(now()->addSeconds($timer));
    }
}
