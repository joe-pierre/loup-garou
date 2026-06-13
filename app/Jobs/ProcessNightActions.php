<?php

namespace App\Jobs;

use App\Events\Game\MayorSuccessionStarted;
use App\Events\Game\PlayerEliminated;
use App\Models\Game;
use App\Notifications\PlayerKilledNightNotification;
use App\Services\VoteService;
use App\Services\WinConditionChecker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ProcessNightActions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $gameId,
        public readonly int $round,
    ) {}

    public function handle(VoteService $voteService, WinConditionChecker $winChecker): void
    {
        $game = null;

        DB::transaction(function () use (&$game) {
            $locked = Game::where('id', $this->gameId)
                ->whereIn('status', ['night', 'wolves_turn'])
                ->where('round', $this->round)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return;
            }

            $locked->update(['status' => 'processing_night']);
            $game = $locked;
        });

        if (! $game) {
            return;
        }

        $victim = $voteService->resolveNightVote($game);

        if ($victim) {
            $victim->update(['is_alive' => false]);
            broadcast(new PlayerEliminated($game, $victim, 'night_kill'));

            if ($victim->isHunter()) {
                Cache::put("hunter_must_shoot_{$game->id}", $victim->id, now()->addMinutes(10));
            }

            try {
                $victim->load('user');
                $victim->user->notify(new PlayerKilledNightNotification());
            } catch (\Throwable) {}
        }

        if ($winChecker->check($game)) {
            return;
        }

        $witch = $game->players()->where('role', 'witch')->where('is_alive', true)->first();
        if ($witch) {
            ProcessWitchTurn::dispatch($game->id, $game->round)->delay(0);
        }

        if ($victim?->is_mayor) {
            $successionDelay = $victim->is_inactive
                ? 0
                : config('game.timers.mayor_succession', 15);

            broadcast(new MayorSuccessionStarted($game, $victim->pseudo));
            ProcessMayorSuccession::dispatch($game->id, $game->round, shouldStartNight: true)
                ->delay(now()->addSeconds($successionDelay));
        }

        ProcessNightEnd::dispatch($game->id, $game->round)
            ->delay(now()->addSeconds($game->timer('mayor_succession') + 5));
    }
}