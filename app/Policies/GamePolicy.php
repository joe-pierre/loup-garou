<?php

namespace App\Policies;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;

class GamePolicy
{
    public function viewHistory(User $user, Game $game): bool
    {
        return $game->players()->where('user_id', $user->id)->exists();
    }

    public function updateSettings(User $user, Game $game, GamePlayer $player): bool
    {
        return $player->is_host;
    }
}
