<?php

namespace App\Jobs;

use App\Models\Game;
use App\Services\PhaseManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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

        // Double-check Workflow : 'processing_night' (Tâches E-H) reste hors périmètre et bypasse le guard.
        if ($game->status === 'night' && ! $game->canTransition('start_day')) {
            Log::warning("Transition 'start_day' refusée depuis status={$game->status}");
            return;
        }

        $hunterId = Cache::pull("hunter_must_shoot_{$game->id}");
        if ($hunterId) {
            ProcessHunterTurn::dispatch($game->id, $game->round, $hunterId)->delay(0);
            return;
        }

        $phaseManager->endNight($game);
    }
}
