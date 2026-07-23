<?php

namespace App\Services;

use App\Models\Game;
use App\Models\GameAction;
use App\Models\GamePlayer;

/**
 * Reconstruit l'état de la sous-phase de nuit active pour un joueur donné,
 * à partir de la vérité persistée `games.night_sub_phase` — jamais depuis
 * l'historique d'events WebSocket (par nature non rejouable, un client qui
 * s'abonne après coup ne reçoit jamais un broadcast déjà émis).
 *
 * Consommé par GameController::state(), au chargement de page ET à la
 * reconnexion Echo (même endpoint, appelé deux fois côté client) pour
 * rattraper l'écran de rôle actif après un refresh ou une coupure réseau
 * en cours de nuit (voir DECISIONS.md "Resynchronisation sous-phases de nuit").
 *
 * Ne modifie aucun état — lecture seule, aucun effet sur la résolution des
 * votes/actions des rôles.
 */
class NightResyncService
{
    public function __construct(private VoteService $voteService) {}

    /**
     * @return array{phase: string, remaining_seconds: int, already_acted: bool, payload: array}|null
     *         null si aucune sous-phase de nuit n'est active pour CE joueur en particulier
     *         (mauvais rôle, phase pas encore démarrée, ou hors phase nuit).
     */
    public function currentSubPhase(Game $game, GamePlayer $player): ?array
    {
        if (! $game->isNightPhase()) {
            return null;
        }

        $phase = $game->night_sub_phase;

        if ($phase === null || ! $this->appliesToPlayer($phase, $player)) {
            return null;
        }

        return match ($phase) {
            'cupidon_turn'    => $this->cupidonPayload($game, $player),
            'seer_turn'       => $this->seerPayload($game, $player),
            'werewolves_turn' => $this->werewolvesPayload($game, $player),
            'witch_turn'      => $this->witchPayload($game, $player),
            'hunter_turn'     => $this->hunterPayload($game, $player),
            default           => null,
        };
    }

    /**
     * Le chasseur est mort quand son tour de tir est actif (contrairement aux
     * quatre autres rôles, vivants pendant leur tour) — voir SPEC_TIMERS.md §2.
     */
    private function appliesToPlayer(string $phase, GamePlayer $player): bool
    {
        return match ($phase) {
            'cupidon_turn'    => $player->role === 'cupidon' && $player->is_alive,
            'seer_turn'       => $player->role === 'seer' && $player->is_alive,
            'werewolves_turn' => $player->isWerewolf() && $player->is_alive,
            'witch_turn'      => $player->isWitch() && $player->is_alive,
            'hunter_turn'     => $player->isHunter() && ! $player->is_alive,
            default           => false,
        };
    }

    private function alreadyActed(Game $game, GamePlayer $player, array $types): bool
    {
        return GameAction::where('game_id', $game->id)
            ->where('player_id', $player->id)
            ->where('round', $game->round)
            ->whereIn('type', $types)
            ->exists();
    }

    private function cupidonPayload(Game $game, GamePlayer $player): array
    {
        return [
            'phase'             => 'cupidon_turn',
            'remaining_seconds' => $game->phaseRemainingSeconds(),
            'already_acted'     => $this->alreadyActed($game, $player, ['cupidon_link']),
            'payload'           => [],
        ];
    }

    private function seerPayload(Game $game, GamePlayer $player): array
    {
        $check = GameAction::where('game_id', $game->id)
            ->where('player_id', $player->id)
            ->where('round', $game->round)
            ->where('type', 'seer_check')
            ->first();

        $result = null;
        if ($check) {
            $target = GamePlayer::find($check->target_player_id);
            if ($target) {
                $result = [
                    'pseudo'      => $target->pseudo,
                    'role'        => $target->role,
                    'is_werewolf' => $target->role === 'werewolf',
                ];
            }
        }

        return [
            'phase'             => 'seer_turn',
            'remaining_seconds' => $game->phaseRemainingSeconds(),
            'already_acted'     => $check !== null,
            'payload'           => ['result' => $result],
        ];
    }

    private function werewolvesPayload(Game $game, GamePlayer $player): array
    {
        $eligibleTargets = $game->alivePlayers()
            ->whereNotIn('role', ['werewolf', 'white_wolf'])
            ->get()
            ->map(fn (GamePlayer $p) => ['id' => $p->id, 'pseudo' => $p->pseudo])
            ->values()
            ->toArray();

        $voteState = $this->voteService->getNightVoteState($game);
        $myState   = collect($voteState)->firstWhere('player_id', $player->id);

        return [
            'phase'             => 'werewolves_turn',
            'remaining_seconds' => $game->phaseRemainingSeconds(),
            'already_acted'     => (bool) ($myState['has_voted'] ?? false),
            'payload'           => [
                'eligible_targets' => $eligibleTargets,
                'vote_state'       => $voteState,
                'my_target_id'     => $myState['target_player_id'] ?? null,
            ],
        ];
    }

    private function witchPayload(Game $game, GamePlayer $player): array
    {
        $victim = $this->voteService->resolveNightVoteFromAction($game);

        $settings = $player->settings ?? [];
        $healUsed = $settings['witch_heal_used'] ?? false;
        $killUsed = $settings['witch_kill_used'] ?? false;

        return [
            'phase'             => 'witch_turn',
            'remaining_seconds' => $game->phaseRemainingSeconds(),
            'already_acted'     => $this->alreadyActed($game, $player, ['witch_heal', 'witch_kill', 'witch_pass']),
            'payload'           => [
                'victim'         => $victim ? ['id' => $victim->id, 'pseudo' => $victim->pseudo] : null,
                'heal_available' => ! $healUsed && $victim !== null,
                'kill_available' => ! $killUsed,
            ],
        ];
    }

    private function hunterPayload(Game $game, GamePlayer $player): array
    {
        return [
            'phase'             => 'hunter_turn',
            'remaining_seconds' => $game->phaseRemainingSeconds(),
            'already_acted'     => $this->alreadyActed($game, $player, ['hunter_shot']),
            'payload'           => [],
        ];
    }
}
