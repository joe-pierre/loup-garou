<?php

namespace App\Jobs;

use App\Events\Game\HunterTurnStarted;
use App\Models\Game;
use App\Services\PhaseManager;
use App\Services\WinConditionChecker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessHunterTurn implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $gameId,
        public readonly int $round,
        public readonly int $hunterId,
    ) {}

    public function handle(PhaseManager $phaseManager, WinConditionChecker $winChecker): void
    {
        $game = Game::find($this->gameId);

        if (! $game || $game->round !== $this->round) {
            return;
        }

        // Mémorise depuis quelle macro-phase ce tour de chasseur a été déclenché,
        // pour que ProcessHunterAutoAction sache quelle transition appliquer
        // sans risquer de la déclencher deux fois (Guard #2).
        $fromNight = in_array($game->status, ['night', 'processing_night']);

        $hunter = $game->players()->where('id', $this->hunterId)->first();

        $alreadyShot = $game->actions()
            ->where('round', $this->round)
            ->where('type', 'hunter_shot')
            ->where('player_id', $this->hunterId)
            ->exists();

        if (! $hunter || $hunter->is_alive || ! $hunter->isHunter() || $alreadyShot) {
            if (! $winChecker->check($game)) {
                $fromNight ? $phaseManager->endNight($game) : $phaseManager->startNight($game);
            }
            return;
        }

        broadcast(new HunterTurnStarted($game, $hunter));

        ProcessHunterAutoAction::dispatch($this->gameId, $this->round, $this->hunterId, $fromNight)
            ->delay(now()->addSeconds($game->timer('hunter')));
    }
}
