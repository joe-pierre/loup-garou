<?php

namespace App\Jobs;

use App\Events\Game\WerewolvesTurnStarted;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ProcessWerewolvesTurn implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $gameId) {}

    public function handle(): void
    {
        $game = null;
        $wolvesTimer = 0;
        $round = 0;

        DB::transaction(function () use (&$game, &$wolvesTimer, &$round) {
            $locked = Game::where('id', $this->gameId)
                ->where('status', 'night')
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return;
            }

            $wolvesTimer = $locked->timer('werewolves');
            $round       = $locked->round;

            $locked->update([
                'status'         => 'wolves_turn',
                'phase_deadline' => now()->addSeconds($wolvesTimer),
            ]);

            $game = $locked;
        });

        if (! $game) {
            return;
        }

        $eligibleTargets = $game->alivePlayers()
            ->whereNotIn('role', ['werewolf', 'white_wolf'])
            ->get()
            ->map(fn (GamePlayer $p) => ['id' => $p->id, 'pseudo' => $p->pseudo])
            ->values()
            ->toArray();

        broadcast(new WerewolvesTurnStarted($game, $eligibleTargets));

        ProcessNightActions::dispatch($this->gameId, $round)
            ->delay(now()->addSeconds($wolvesTimer));
    }
}