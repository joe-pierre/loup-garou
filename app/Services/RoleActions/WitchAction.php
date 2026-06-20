<?php

namespace App\Services\RoleActions;

use App\Models\Game;
use App\Models\GameAction;
use App\Models\GamePlayer;
use App\Services\PhaseGuard;
use App\Services\VoteService;
use Illuminate\Support\Facades\DB;

class WitchAction
{
    public function __construct(private VoteService $voteService) {}

    /**
     * Action de la Sorcière : sauver la victime des loups, empoisonner un joueur, ou passer son tour.
     * Guard atomique : impossible d'agir deux fois dans le même round (witch_heal/witch_kill/witch_pass).
     * La sorcière ne peut pas s'auto-sauver (action 'heal' refusée si la victime est la sorcière elle-même).
     * Si la cible d'un 'kill' est le chasseur, un enregistrement 'hunter_pending' est créé en DB.
     *
     * @param  GamePlayer  $witch    La sorcière (rôle 'witch' requis, doit être vivante)
     * @param  string      $action   Action choisie : 'heal', 'kill' ou 'pass'
     * @param  int|null    $targetId ID du joueur cible (requis pour 'heal' et 'kill', null pour 'pass')
     * @return array{action: string, target: ?GamePlayer} Action effectuée et joueur cible le cas échéant
     * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException (403) si $witch n'est pas la sorcière, est morte, ou s'auto-sauve
     * @throws \Symfony\Component\HttpKernel\Exception\ConflictHttpException     (409) si hors phase nuit, ou action déjà posée ce round
     */
    public function act(GamePlayer $witch, string $action, ?int $targetId): array
    {
        if (! $witch->isWitch()) {
            abort(403, 'Seule la sorcière peut utiliser ce pouvoir.');
        }

        if (! $witch->is_alive) {
            abort(403, 'Un joueur éliminé ne peut pas agir.');
        }

        $game = $witch->game;

        if (! PhaseGuard::canWitchAct($game)) {
            abort(409, 'L\'action de la sorcière n\'est pas disponible hors phase nuit.');
        }

        $result = DB::transaction(function () use ($witch, $action, $targetId, $game) {
            $alreadyActed = GameAction::where('game_id', $game->id)
                ->where('player_id', $witch->id)
                ->where('round', $game->round)
                ->whereIn('type', ['witch_heal', 'witch_kill', 'witch_pass'])
                ->lockForUpdate()
                ->exists();

            if ($alreadyActed) {
                abort(409, 'Vous avez déjà utilisé votre pouvoir ce round.');
            }

            $settings = $witch->settings ?? [];
            $target   = null;

            if ($action === 'heal') {
                $victim = $this->voteService->resolveNightVoteFromAction($game);

                if (! $victim || $victim->id === $witch->id) {
                    abort(403, 'Aucune victime à sauver ce round.');
                }

                if ($settings['witch_heal_used'] ?? false) {
                    abort(409, 'Vous avez déjà utilisé votre potion de soin.');
                }

                $victim->update(['is_alive' => true]);
                $settings['witch_heal_used'] = true;
                $witch->update(['settings' => $settings]);

                GameAction::create([
                    'game_id'          => $game->id,
                    'player_id'        => $witch->id,
                    'type'             => 'witch_heal',
                    'target_player_id' => $victim->id,
                    'round'            => $game->round,
                    'phase'            => 'night',
                ]);

                $target = $victim;
            } elseif ($action === 'kill') {
                if ($targetId === $witch->id) {
                    abort(403, 'La sorcière ne peut pas s\'empoisonner elle-même.');
                }

                $target = GamePlayer::where('id', $targetId)
                    ->where('game_id', $game->id)
                    ->where('is_alive', true)
                    ->lockForUpdate()
                    ->first();

                if (! $target) {
                    abort(404, 'Cible invalide.');
                }

                if ($settings['witch_kill_used'] ?? false) {
                    abort(409, 'Vous avez déjà utilisé votre potion de poison.');
                }

                $target->update(['is_alive' => false]);
                $settings['witch_kill_used'] = true;
                $witch->update(['settings' => $settings]);

                GameAction::create([
                    'game_id'          => $game->id,
                    'player_id'        => $witch->id,
                    'type'             => 'witch_kill',
                    'target_player_id' => $target->id,
                    'round'            => $game->round,
                    'phase'            => 'night',
                ]);

                if ($target->isHunter()) {
                    GameAction::create([
                        'game_id'   => $game->id,
                        'player_id' => $target->id,
                        'type'      => 'hunter_pending',
                        'round'     => $game->round,
                        'phase'     => 'night',
                    ]);
                }
            } else {
                GameAction::create([
                    'game_id'          => $game->id,
                    'player_id'        => $witch->id,
                    'type'             => 'witch_pass',
                    'target_player_id' => null,
                    'round'            => $game->round,
                    'phase'            => 'night',
                ]);
            }

            return ['action' => $action, 'target' => $target];
        });

        return $result;
    }
}
