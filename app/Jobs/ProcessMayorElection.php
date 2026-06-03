<?php

namespace App\Jobs;

use App\Events\Game\MayorElected;
use App\Events\Game\NightStarted;
use App\Models\Game;
use App\Services\VoteService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessMayorElection implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $gameId) {}

    public function handle(VoteService $voteService): void
    {
        $game = Game::find($this->gameId);

        if (! $game || $game->status !== 'electing_mayor') {
            return;
        }

        $result = $voteService->resolveMayorElection($game);

        // null = status déjà changé entre la vérification et la transaction
        if (! $result) {
            return;
        }

        broadcast(new MayorElected($result['game'], $result['player'], $result['was_random']));
        broadcast(new NightStarted($result['game']));

        ProcessSeerTurn::dispatch($this->gameId)
            ->delay(now()->addSeconds(config('game.timers.seer', 30)));
    }
}
