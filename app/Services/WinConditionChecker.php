<?php

namespace App\Services;

use App\Events\Game\GameFinished;
use App\Models\Game;
use App\Notifications\GameFinishedNotification;
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
