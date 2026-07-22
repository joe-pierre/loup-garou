<?php

namespace App\Services\RoleActions;

use App\Events\Game\LoverRevealed;
use App\Models\GameAction;
use App\Models\GamePlayer;
use App\Services\PhaseGuard;
use Illuminate\Support\Facades\DB;

class CupidonAction extends RoleAction
{
    /**
     * Forme un couple d'amoureux (round 1 uniquement, une seule fois par partie).
     * Guards, dans l'ordre exact (SPEC_CUPIDON.md §4) : rôle, phase, anti-double-action,
     * cibles distinctes, cibles vivantes de la partie.
     *
     * Pose `lover_player_id` symétriquement sur les deux cibles, crée 2 GameAction
     * `cupidon_link` (historique uniquement, jamais lus par la logique de jeu — voir
     * SPEC_CUPIDON.md §2) et notifie chaque amoureux distinct de Cupidon en privé.
     * Cupidon n'est jamais notifié de son propre choix : il le connaît déjà via la
     * réponse de son action (d'où un seul broadcast s'il s'est choisi lui-même).
     *
     * @param  GamePlayer $cupidon   Cupidon (rôle 'cupidon' requis)
     * @param  int        $target1Id ID du premier amoureux (peut être $cupidon->id)
     * @param  int        $target2Id ID du second amoureux (peut être $cupidon->id, distinct de $target1Id)
     * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException (403) si $cupidon n'est pas Cupidon
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException             (409) si hors round 1 / phase nuit, ou action déjà posée ce round
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException             (422) si cibles identiques ou l'une des deux est morte
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException     (404) si une cible n'existe pas dans la partie
     */
    public function link(GamePlayer $cupidon, int $target1Id, int $target2Id): void
    {
        if ($cupidon->role !== 'cupidon') {
            abort(403, 'Seul Cupidon peut utiliser ce pouvoir.');
        }

        $game = $cupidon->game;

        if (! PhaseGuard::canCupidonLink($game)) {
            abort(409, 'Le pouvoir de Cupidon n\'est disponible qu\'au premier round, phase nuit.');
        }

        DB::transaction(function () use ($cupidon, $target1Id, $target2Id, $game) {
            $this->guardNotAlreadyActed($game, $cupidon->id, ['cupidon_link']);

            if ($target1Id === $target2Id) {
                abort(422, 'Cupidon ne peut pas coupler un joueur avec lui-même en double.');
            }

            $target1 = GamePlayer::where('id', $target1Id)
                ->where('game_id', $game->id)
                ->lockForUpdate()
                ->first();

            $target2 = GamePlayer::where('id', $target2Id)
                ->where('game_id', $game->id)
                ->lockForUpdate()
                ->first();

            if (! $target1 || ! $target2) {
                abort(404, 'Cible invalide.');
            }

            if (! $target1->is_alive || ! $target2->is_alive) {
                abort(422, 'Les deux amoureux doivent être vivants.');
            }

            $target1->update(['lover_player_id' => $target2->id]);
            $target2->update(['lover_player_id' => $target1->id]);

            GameAction::create([
                'game_id'          => $game->id,
                'player_id'        => $cupidon->id,
                'type'             => 'cupidon_link',
                'target_player_id' => $target1->id,
                'round'            => $game->round,
                'phase'            => 'night',
            ]);

            GameAction::create([
                'game_id'          => $game->id,
                'player_id'        => $cupidon->id,
                'type'             => 'cupidon_link',
                'target_player_id' => $target2->id,
                'round'            => $game->round,
                'phase'            => 'night',
            ]);

            foreach ([$target1, $target2] as $lover) {
                if ($lover->id === $cupidon->id) {
                    continue;
                }

                $partner = $lover->id === $target1->id ? $target2 : $target1;

                broadcast(new LoverRevealed($game, $lover, $partner));
            }
        });
    }
}
