<?php

namespace App\Services;

use App\Models\GameAction;
use App\Models\GamePlayer;

/**
 * Point d'entrée unique pour éliminer un joueur (is_alive = false).
 *
 * Cascade de mort des amoureux (Cupidon, v1.3, SPEC_CUPIDON.md §5) : si le
 * joueur éliminé a un amoureux (lover_player_id) encore vivant, celui-ci est
 * éliminé à son tour. `lover_player_id` reste toujours null tant que Cupidon
 * n'existe pas comme rôle jouable — no-op garanti sur les parties actuelles.
 *
 * Si l'amoureux qui meurt par cascade est le Chasseur, un `hunter_pending`
 * est créé ici même — sur le modèle exact des 4 call sites existants
 * (ProcessNightActions, WitchAction, VoteService, cible directe du Chasseur)
 * — puisque cette mort n'a pas d'appelant qui puisse faire cette vérification
 * à sa place. Uniquement dans la branche cascade : le `$player` passé en
 * paramètre initial reste sous la responsabilité de l'appelant.
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

                if ($lover->isHunter()) {
                    $game = $lover->game;

                    GameAction::create([
                        'game_id'   => $game->id,
                        'player_id' => $lover->id,
                        'type'      => 'hunter_pending',
                        'round'     => $game->round,
                        'phase'     => $game->isNightPhase() ? 'night' : 'day',
                    ]);
                }
            }
        }
    }
}
