<?php

namespace App\Services;

use App\Models\GamePlayer;

/**
 * Point d'entrée unique pour éliminer un joueur (is_alive = false).
 *
 * Prérequis Cupidon (v1.3, SPEC_CUPIDON.md §5) : la cascade de mort des
 * amoureux sera ajoutée ici plus tard, pour ne dépendre que d'un seul
 * endroit plutôt que des multiples call sites historiques.
 */
class PlayerEliminationService
{
    public function eliminate(GamePlayer $player): void
    {
        $player->update(['is_alive' => false]);
    }
}
