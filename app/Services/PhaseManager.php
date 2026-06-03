<?php

namespace App\Services;

use App\Events\Game\NightStarted;
use App\Jobs\ProcessSeerTurn;
use App\Models\Game;
use Illuminate\Support\Facades\DB;

class PhaseManager
{
    public function startNight(Game $game): void
    {
        DB::transaction(function () use ($game) {
            $locked = Game::where('id', $game->id)
                ->where('status', 'day')
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return;
            }

            $timer = config('game.timers.seer', 30);

            $locked->update([
                'status'         => 'night',
                'round'          => $locked->round + 1,
                'phase_deadline' => now()->addSeconds($timer),
            ]);

            broadcast(new NightStarted($locked));
            ProcessSeerTurn::dispatch($locked->id);
        });
    }
}
