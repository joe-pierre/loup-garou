<?php

namespace App\Jobs;

use App\Models\Game;
use App\Services\PhaseManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessNightEnd implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $gameId,
        public readonly int $round,
    ) {}

    public function handle(PhaseManager $phaseManager): void
    {
        $game = Game::find($this->gameId);

        if (! $game || $game->round !== $this->round || ! in_array($game->status, ['night', 'processing_night'])) {
            return;
        }

        $phaseManager->endNight($game);
    }
}
