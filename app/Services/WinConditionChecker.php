<?php

namespace App\Services;

use App\Events\Game\GameFinished;
use App\Models\Game;
use App\Notifications\GameFinishedNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class WinConditionChecker
{
    public function check(Game $game): bool
    {
        $game->refresh();

        $alivePlayers    = $game->alivePlayers()->get();
        $aliveWerewolves = $alivePlayers->filter(fn ($p) => $p->isWerewolf())->count();
        $aliveOthers     = $alivePlayers->filter(fn ($p) => ! $p->isWerewolf())->count();

        if ($aliveWerewolves > 0 && $aliveWerewolves >= $aliveOthers) {
            $winnerTeam = 'werewolves';
        } elseif ($aliveWerewolves === 0) {
            $winnerTeam = 'villagers';
        } else {
            return false;
        }

        // canTransition() ne connaît que les statuts canoniques du Workflow :
        // 'processing_night'/'processing_day' (Tâches E-H) restent hors de son périmètre et bypassent le guard.
        if (in_array($game->status, ['night', 'day']) && ! $game->canTransition('finish')) {
            Log::warning("Transition 'finish' refusée depuis status={$game->status}");
            return false;
        }

        $game->update([
            'status'      => 'finished',
            'winner_team' => $winnerTeam,
            'finished_at' => now(),
        ]);

        $allPlayers = $game->players()->with('user')->get();
        broadcast(new GameFinished($game, $allPlayers, $winnerTeam));

        try {
            Notification::send(
                $allPlayers->map->user->filter(),
                new GameFinishedNotification($winnerTeam)
            );
        } catch (\Throwable) {}

        return true;
    }
}
