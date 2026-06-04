<?php

namespace App\Policies;

use App\Models\Game;
use App\Models\User;

class GamePolicy
{
    public function viewHistory(User $user, Game $game): bool
    {
        return $game->players()->where('user_id', $user->id)->exists();
    }
}
