<?php

namespace App\Services\RoleActions;

use App\Models\Game;
use App\Models\GameAction;

abstract class RoleAction
{
    protected function guardNotAlreadyActed(Game $game, int $playerId, array $types): void
    {
        $alreadyActed = GameAction::where('game_id', $game->id)
            ->where('player_id', $playerId)
            ->where('round', $game->round)
            ->whereIn('type', $types)
            ->lockForUpdate()
            ->exists();

        if ($alreadyActed) {
            abort(409, 'Vous avez déjà utilisé votre pouvoir ce round.');
        }
    }
}
