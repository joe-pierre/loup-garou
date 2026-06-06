<?php

namespace App\Services;

use App\Events\Game\DayStarted;
use App\Events\Game\NightStarted;
use App\Jobs\ProcessDayVote;
use App\Jobs\ProcessSeerTurn;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Support\Facades\DB;

class PhaseManager
{
    public function startDay(Game $game, ?GamePlayer $victim): void
    {
        $locked = null;
        $timer = $game->timer('day_vote');

        DB::transaction(function () use ($game, &$locked, $timer) {
            $locked = Game::where('id', $game->id)
                ->where('status', 'night')
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return;
            }

            $locked->update([
                'status'         => 'day',
                'phase_deadline' => now()->addSeconds($timer),
            ]);
        });

        if (! $locked) {
            return;
        }

        broadcast(new DayStarted($locked, $victim));
        ProcessDayVote::dispatch($locked->id, $locked->round)
            ->delay(now()->addSeconds($timer));
    }

    public function startNight(Game $game): void
    {
        $locked = null;
        $timer = $game->timer('seer');

        DB::transaction(function () use ($game, &$locked, $timer) {
            $locked = Game::where('id', $game->id)
                ->where('status', 'day')
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return;
            }

            $locked->update([
                'status'         => 'night',
                'round'          => $locked->round + 1,
                'phase_deadline' => now()->addSeconds($timer),
            ]);
        });

        if (! $locked) {
            return;
        }

        broadcast(new NightStarted($locked));
        ProcessSeerTurn::dispatch($locked->id);
    }
}
