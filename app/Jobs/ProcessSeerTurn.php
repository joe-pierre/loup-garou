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

        $seer = $game->players()->where('role', 'seer')->where('is_alive', true)->first();

        // Voyante morte/absente/inactive → loups immédiatement
        if (! $seer || $seer->is_inactive) {
            ProcessWerewolvesTurn::dispatch($this->gameId);
            return;
        }

        $seerTimer = $game->timer('seer');
        $halfTimer = (int) ceil($seerTimer / 2);

        $game->update(['phase_deadline' => now()->addSeconds($seerTimer)]);

        broadcast(new SeerTurnStarted($game, $seer));

        // À mi-timer : auto-inspect si la voyante n'a pas agi
        ProcessSeerAutoAction::dispatch($this->gameId, $seer->id, $game->round)
            ->delay(now()->addSeconds($halfTimer));

        // Après timer complet + 5s (pour laisser la voyante voir le résultat)
        // → loups démarrent
        ProcessWerewolvesTurn::dispatch($this->gameId)
            ->delay(now()->addSeconds($seerTimer + 2));
    }
}