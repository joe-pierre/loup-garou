<?php

namespace App\Services;

use App\Events\Game\PlayerEliminated;
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
 * La mort par cascade n'ayant aucun appelant en dehors de ce service, elle
 * broadcaste elle-même son propre `PlayerEliminated` (reason 'heartbreak')
 * — `$lover->load('user')` avant broadcast, même geste que les call sites
 * existants (`google_name` n'est plus dans `PlayerEliminated::broadcastWith()`
 * depuis le fix vie privée du 2026-06-24, mais le `load('user')` reste fait
 * partout par cohérence de pattern). Si l'amoureux cascadé est le Chasseur,
 * un `hunter_pending` est créé ici même, au même titre que le broadcast.
 * Uniquement dans la branche cascade : le `$player` passé en paramètre
 * initial reste sous la responsabilité de l'appelant (broadcast + éventuel
 * hunter_pending gérés par lui, non dupliqués ici).
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

                $game = $lover->game;

                $lover->load('user');
                broadcast(new PlayerEliminated($game, $lover, 'heartbreak'));

                if ($lover->isHunter()) {
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
