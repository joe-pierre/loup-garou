<?php

namespace App\Jobs;

use App\Models\Game;
use App\Services\PhaseManager;
use App\Services\WinConditionChecker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessHunterAutoAction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $gameId,
        public readonly int $round,
        public readonly int $hunterId,
        public readonly bool $fromNight,
    ) {}

    public function handle(PhaseManager $phaseManager, WinConditionChecker $winChecker): void
    {
        $game = Game::find($this->gameId);

        if (! $game || $game->round !== $this->round) {
            return;
        }

        // Idempotent : si la transition a déjà eu lieu (tir volontaire traité avant ce job),
        // le statut n'est plus celui d'où ce tour de chasseur a été déclenché.
        $expectedStatuses = $this->fromNight ? ['night', 'processing_night'] : ['day', 'processing_day'];

        if (! in_array($game->status, $expectedStatuses)) {
            return;
        }

        if ($winChecker->check($game)) {
            return;
        }

        // Chasseur inactif : pas de tir, pas d'élimination supplémentaire — on transitionne simplement.
        if ($this->fromNight) {
            $phaseManager->endNight($game);
        } else {
            $phaseManager->startNight($game);
        }
    }
}
