<?php

namespace App\Services;

use App\Events\Game\DayStarted;
use App\Events\Game\NightStarted;
use App\Jobs\ProcessDayVote;
use App\Jobs\ProcessSeerTurn;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PhaseManager
{
    public function startDay(
        Game $game,
        ?GamePlayer $victim,
        bool $witchActed = false,
        ?int $savedPlayerId = null,
    ): void {
        $game->refresh();
        $locked = null;
        $timer  = $game->timer('day_vote');

        DB::transaction(function () use ($game, &$locked, $timer) {
            $locked = Game::where('id', $game->id)
                ->whereIn('status', ['night', 'processing_night'])
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return;
            }

            // canTransition() ne connaît que les statuts canoniques du Workflow :
            // 'processing_night' (Tâche E-H) reste hors de son périmètre et bypasse le guard.
            if ($locked->status === 'night' && ! $locked->canTransition('start_day')) {
                Log::warning("Transition 'start_day' refusée depuis status={$locked->status}");
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

        broadcast(new DayStarted($locked, $victim, $witchActed, $savedPlayerId));
        ProcessDayVote::dispatch($locked->id, $locked->round)
            ->delay(now()->addSeconds($timer));
    }

    public function startNight(Game $game): void
    {
        $game->refresh();
        $locked = null;
        $timer  = $game->timer('seer');

        DB::transaction(function () use ($game, &$locked, $timer) {
            $locked = Game::where('id', $game->id)
                ->whereIn('status', ['day', 'processing_day'])
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return;
            }

            // canTransition() ne connaît que les statuts canoniques du Workflow :
            // 'processing_day' (Tâches F-G) reste hors de son périmètre et bypasse le guard.
            if ($locked->status === 'day' && ! $locked->canTransition('continue_night')) {
                Log::warning("Transition 'continue_night' refusée depuis status={$locked->status}");
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
        ProcessSeerTurn::dispatch($locked->id)
            ->delay(now()->addSeconds(config('game.timers.night_start_delay', 4)));
    }

    public function endNight(Game $game): void
    {
        $game->refresh();

        if (! in_array($game->status, ['night', 'processing_night'])) {
            return;
        }

        if (app(WinConditionChecker::class)->check($game)) {
            return;
        }

        $victim = app(VoteService::class)->resolveNightVote($game);

        if ($victim && $victim->is_alive) {
            $victim = null; // sauvé par la sorcière
        }

        // Vérifier si la sorcière a agi (heal ou kill) ce round
        $witchActed = $game->actions()
            ->where('round', $game->round)
            ->whereIn('type', ['witch_heal', 'witch_kill'])
            ->exists();

        // Récupérer l'id du joueur sauvé par la sorcière ce round (si applicable)
        $savedPlayerId = null;
        if ($witchActed) {
            $healAction = $game->actions()
                ->where('round', $game->round)
                ->where('type', 'witch_heal')
                ->first();
            $savedPlayerId = $healAction?->target_player_id;
        }

        $this->startDay($game, $victim, $witchActed, $savedPlayerId);
    }
}