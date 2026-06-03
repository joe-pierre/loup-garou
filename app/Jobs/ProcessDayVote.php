<?php

namespace App\Jobs;

use App\Models\Game;
use App\Services\VoteService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessDayVote implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $gameId,
        public readonly int $round,
    ) {}

    public function handle(VoteService $voteService): void
    {
        $game = Game::find($this->gameId);

        // Double-fire guard : statut ET round doivent correspondre
        if (! $game || $game->status !== 'day' || $game->round !== $this->round) {
            return;
        }

        $voteService->resolveDayVote($game);
    }
}
