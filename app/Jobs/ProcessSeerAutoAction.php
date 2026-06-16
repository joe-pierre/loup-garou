<?php

namespace App\Jobs;

use App\Events\Game\SeerResult;
use App\Models\Game;
use App\Models\GameAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Auto-inspection de la voyante déclenchée à mi-timer (ceil(seer/2)).
 *
 * Dispatché par ProcessSeerTurn si la voyante est active.
 * Si la voyante n'a pas encore agi, choisit une cible aléatoire et crée un seer_check.
 *
 * ⚠️ Divergence avec SPEC_TIMERS §3.2 : ce job ne dispatche PAS ProcessWerewolvesTurn
 * en cas d'inactivité — c'est ProcessSeerTurn qui le dispatche systématiquement
 * à (seer+2). Voir DECISIONS.md "Étape 5 — AutoActionTest adapté au comportement réel".
 */
class ProcessSeerAutoAction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param int $gameId Identifiant de la partie.
     * @param int $seerId Identifiant de la voyante.
     * @param int $round  Round de référence (guard anti-round-décalé).
     */
    public function __construct(
        public readonly int $gameId,
        public readonly int $seerId,
        public readonly int $round,
    ) {}

    /**
     * Crée un seer_check aléatoire si la voyante n'a pas encore agi ce round.
     *
     * Guards d'entrée :
     *   - La partie existe.
     *   - status === 'night'.
     *   - round === $this->round.
     *   - Aucun seer_check existant pour ce seerId + round.
     *   - La voyante est encore en vie.
     *
     * Effets :
     *   - Crée un GameAction de type seer_check avec une cible aléatoire.
     *   - Broadcast SeerResult sur le canal privé de la voyante.
     *   - Ne dispatche PAS ProcessWerewolvesTurn (géré systématiquement par ProcessSeerTurn).
     */
    public function handle(): void
    {
        $game = Game::find($this->gameId);

        if (! $game || $game->status !== 'night' || $game->round !== $this->round) {
            return;
        }

        // Voyante a déjà agi → rien à faire
        $alreadyActed = GameAction::where('game_id', $this->gameId)
            ->where('player_id', $this->seerId)
            ->where('type', 'seer_check')
            ->where('round', $this->round)
            ->exists();

        if ($alreadyActed) {
            return;
        }

        $seer = $game->players()->where('id', $this->seerId)->first();
        if (! $seer || ! $seer->is_alive) {
            return;
        }

        // Choisir une cible aléatoire (pas la voyante elle-même)
        $target = $game->alivePlayers()
            ->where('id', '!=', $this->seerId)
            ->inRandomOrder()
            ->first();

        if (! $target) {
            return;
        }

        GameAction::create([
            'game_id'          => $this->gameId,
            'player_id'        => $this->seerId,
            'type'             => 'seer_check',
            'target_player_id' => $target->id,
            'round'            => $this->round,
            'phase'            => 'night',
        ]);

        // Broadcast le résultat sur le channel privé de la voyante
        // → côté client seer_result déclenche l'affichage
        // → après 5s ProcessWerewolvesTurn démarre (géré dans ProcessSeerTurn)
        broadcast(new SeerResult($game, $seer, $target));
    }
}