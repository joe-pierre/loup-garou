<?php

namespace App\Services;

use App\Models\Game;

// Implémenté en Tâche 21
class WinConditionChecker
{
    public function check(Game $game): bool
    {
        // TODO (tâche 21) : après broadcast(new GameFinished(...)) :
        // try {
        //     $allPlayers = $game->players()->with('user')->get();
        //     \Illuminate\Support\Facades\Notification::send(
        //         $allPlayers->map->user->filter(),
        //         new \App\Notifications\GameFinishedNotification($game->winner_team)
        //     );
        // } catch (\Throwable) {}

        return false;
    }
}
