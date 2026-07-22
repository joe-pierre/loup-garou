<?php

namespace App\Services\RoleActions;

use App\Models\Game;
use App\Models\GameAction;
use App\Models\GamePlayer;
use Illuminate\Support\Facades\DB;

class SeerAction extends RoleAction
{
    /**
     * Enregistre l'inspection de la voyante sur une cible et retourne le joueur inspecté.
     * Guard atomique : impossible d'agir deux fois dans le même round.
     *
     * @param  GamePlayer $seer     La voyante (rôle 'seer' requis)
     * @param  int        $targetId ID du joueur à inspecter (ne peut pas être la voyante elle-même)
     * @return GamePlayer            Le joueur inspecté
     * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException (403) si $seer n'est pas voyante
     * @throws \Symfony\Component\HttpKernel\Exception\ConflictHttpException     (409) si la phase n'est pas 'night' ou si l'action a déjà été posée ce round
     */
    public function check(GamePlayer $seer, int $targetId): GamePlayer
    {
        if ($seer->role !== 'seer') {
            abort(403, 'Seule la voyante peut utiliser ce pouvoir.');
        }

        $game = $seer->game;

        if ($game->status !== 'night') {
            abort(409, 'L\'action de la voyante n\'est pas disponible hors phase nuit.');
        }

        return DB::transaction(function () use ($seer, $targetId, $game) {
            $this->guardNotAlreadyActed($game, $seer->id, ['seer_check']);

            GameAction::create([
                'game_id'          => $game->id,
                'player_id'        => $seer->id,
                'type'             => 'seer_check',
                'target_player_id' => $targetId,
                'round'            => $game->round,
                'phase'            => 'night',
            ]);

            return GamePlayer::findOrFail($targetId);
        });
    }
}
