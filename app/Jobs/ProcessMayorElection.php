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
use Illuminate\Support\Facades\Log;

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

        // Double-check Workflow : statut 'electing_mayor' toujours canonique ici.
        if (! $game->canTransition('start_night')) {
            Log::warning("Transition 'start_night' refusée depuis status={$game->status}");
            return;
        }

        $result = $voteService->resolveMayorElection($game);

        // null = status déjà changé entre la vérification et la transaction
        if (! $result) {
            return;
        }

        broadcast(new MayorElected($result['game'], $result['player'], $result['was_random']));
        broadcast(new NightStarted($result['game']));

        // Délai avant le premier tour voyante : laisse le temps à l'UI d'afficher MayorElected.
        ProcessSeerTurn::dispatch($this->gameId)
            ->delay(now()->addSeconds(config('game.timers.mayor_reveal')));
    }
}
