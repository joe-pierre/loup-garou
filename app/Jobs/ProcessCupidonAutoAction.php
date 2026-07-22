<?php

namespace App\Jobs;

use App\Models\Game;
use App\Models\GameAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Timeout du tour de Cupidon, déclenché par ProcessCupidonTurn après le timer 'cupidon'.
 *
 * Contrairement au Chasseur (tir aléatoire au timeout), aucun couple n'est formé
 * si Cupidon n'a pas agi volontairement — décision assumée, voir SPEC_CUPIDON.md §1 :
 * imposer un couple au hasard aurait des conséquences trop lourdes (camp de victoire
 * entier) pour être laissé au hasard.
 *
 * Ce job ne fait donc jamais rien d'autre que vérifier ses guards puis, dans tous
 * les cas, retourner sans effet — pas de couple, pas de broadcast, pas de dispatch.
 * ⚠️ Ne chaîne pas ProcessSeerTurn à ce stade (voir ProcessCupidonTurn, DECISIONS.md) :
 * ce chaînage sera ajouté avec l'intégration dans PhaseManager (SPEC_CUPIDON.md §8, tâche 4).
 */
class ProcessCupidonAutoAction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param int $gameId    Identifiant de la partie.
     * @param int $cupidonId Identifiant de Cupidon.
     * @param int $round     Round de référence (guard anti-round-décalé).
     */
    public function __construct(
        public readonly int $gameId,
        public readonly int $cupidonId,
        public readonly int $round,
    ) {}

    public function handle(): void
    {
        $game = Game::find($this->gameId);

        if (! $game || $game->status !== 'night' || $game->round !== $this->round) {
            return;
        }

        // Cupidon a déjà agi via CupidonAction::link() → rien à faire ici.
        $alreadyActed = GameAction::where('game_id', $this->gameId)
            ->where('player_id', $this->cupidonId)
            ->where('type', 'cupidon_link')
            ->where('round', $this->round)
            ->exists();

        if ($alreadyActed) {
            return;
        }

        // Timeout sans action volontaire : aucun couple formé, aucun effet.
    }
}
