<?php

namespace App\Services\RoleActions;

use App\Models\Game;
use App\Models\GameAction;
use App\Models\GamePlayer;
use App\Services\PhaseGuard;
use App\Services\PlayerEliminationService;
use Illuminate\Support\Facades\DB;

class HunterAction extends RoleAction
{
    public function __construct(private PlayerEliminationService $eliminationService) {}

    /**
     * Tir du Chasseur : élimine une cible après la mort du chasseur (nuit ou jour).
     * Guard atomique : impossible de tirer deux fois dans le même round.
     * La phase du GameAction est déterminée selon le statut courant (night/processing_night → 'night', sinon 'day').
     *
     * @param  GamePlayer $hunter   Le chasseur éliminé (rôle 'hunter' requis, doit être mort)
     * @param  int        $targetId ID du joueur vivant à éliminer (ne peut pas être le chasseur lui-même)
     * @return GamePlayer            Le joueur éliminé par le tir
     * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException (403) si $hunter n'est pas le chasseur ou est encore vivant
     * @throws \Symfony\Component\HttpKernel\Exception\ConflictHttpException     (409) si phase invalide ou tir déjà effectué ce round
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException     (404) si la cible est invalide ou déjà morte
     */
    public function shoot(GamePlayer $hunter, int $targetId): GamePlayer
    {
        if (! $hunter->isHunter()) {
            abort(403, 'Seul le chasseur peut utiliser ce pouvoir.');
        }

        if ($hunter->is_alive) {
            abort(403, 'Le chasseur ne peut tirer qu\'après sa mort.');
        }

        $game = $hunter->game;

        if (! PhaseGuard::canHunterShoot($game)) {
            abort(409, 'Le tir du chasseur n\'est pas disponible dans cette phase.');
        }

        if ($targetId === $hunter->id) {
            abort(403, 'Le chasseur ne peut pas se tirer lui-même.');
        }

        return DB::transaction(function () use ($hunter, $targetId, $game) {
            $this->guardNotAlreadyActed($game, $hunter->id, ['hunter_shot']);

            $target = GamePlayer::where('id', $targetId)
                ->where('game_id', $game->id)
                ->where('is_alive', true)
                ->lockForUpdate()
                ->first();

            if (! $target) {
                abort(404, 'Cible invalide.');
            }

            $this->eliminationService->eliminate($target);

            GameAction::create([
                'game_id'          => $game->id,
                'player_id'        => $hunter->id,
                'type'             => 'hunter_shot',
                'target_player_id' => $target->id,
                'round'            => $game->round,
                'phase'            => PhaseGuard::isNightOrProcessing($game) ? 'night' : 'day',
            ]);

            return $target;
        });
    }
}
