<?php

namespace App\Services;

use App\Models\GamePlayer;

/**
 * Point d'entrée unique pour éliminer un joueur (is_alive = false).
 *
 * Cascade de mort des amoureux (Cupidon, v1.3, SPEC_CUPIDON.md §5) : si le
 * joueur éliminé a un amoureux (lover_player_id) encore vivant, celui-ci est
 * éliminé à son tour. `lover_player_id` reste toujours null tant que Cupidon
 * n'existe pas comme rôle jouable — no-op garanti sur les parties actuelles.
 */
class PlayerEliminationService
{
    public function eliminate(GamePlayer $player): void
    {
        $player->update(['is_alive' => false]);

        if ($player->lover_player_id) {
            $lover = GamePlayer::find($player->lover_player_id);
            if ($lover && $lover->is_alive && $lover->id !== $player->id) {
                $this->eliminate($lover);
            }
        }
    }
}
