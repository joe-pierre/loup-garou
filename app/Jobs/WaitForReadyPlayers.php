<?php

namespace App\Jobs;

use App\Events\Game\MayorElectionStarted;
use App\Models\Game;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Jobs\ProcessMayorElection;

class WaitForReadyPlayers implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $gameId) {}

    public function handle(): void
    {
        $game = Game::find($this->gameId);

        // La partie a été annulée ou a déjà progressé au-delà de la révélation des rôles
        if (! $game || $game->status !== 'electing_mayor') {
            return;
        }

        // phase_deadline non null → MayorElectionStarted déjà broadcasté
        // (déclenché tôt par le ready endpoint si tous les joueurs étaient prêts)
        if ($game->phase_deadline !== null) {
            return;
        }

        $timer    = $game->timer('mayor_election');
        $deadline = now()->addSeconds($timer);

        $game->update(['phase_deadline' => $deadline]);

        broadcast(new MayorElectionStarted($game));
        ProcessMayorElection::dispatch($game->id)->delay(now()->addSeconds($timer));
    }
}
