<?php

namespace App\Jobs;

use App\Events\Game\CupidonTurnStarted;
use App\Models\Game;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Démarre le tour de Cupidon (round 1 exclusivement, avant la Voyante).
 *
 * ⚠️ Non branché dans PhaseManager::startNight() à ce stade (SPEC_CUPIDON.md §8,
 * tâche 3) — dispatché uniquement manuellement en test. L'intégration réelle
 * (condition round === 1, chaînage vers ProcessSeerTurn) est l'étape suivante.
 *
 * Si Cupidon mort/absent/inactif ou composition sans Cupidon → aucun effet
 * (pas de broadcast, pas de dispatch — voir DECISIONS.md pour le choix de
 * ne pas chaîner ProcessSeerTurn depuis cette étape).
 * Sinon :
 *   - Broadcast CupidonTurnStarted sur le canal privé de Cupidon.
 *   - Dispatche ProcessCupidonAutoAction après le timer 'cupidon'.
 */
class ProcessCupidonTurn implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param int $gameId Identifiant de la partie.
     * @param int $round  Round de référence (guard anti-round-décalé).
     */
    public function __construct(public readonly int $gameId, public readonly int $round) {}

    public function handle(): void
    {
        $game = Game::find($this->gameId);

        if (! $game || $game->status !== 'night' || $game->round !== $this->round) {
            return;
        }

        $cupidon = $game->players()->where('role', 'cupidon')->where('is_alive', true)->first();

        // Pas de Cupidon dans la composition, mort, ou inactif → rien à faire.
        if (! $cupidon || $cupidon->is_inactive) {
            return;
        }

        broadcast(new CupidonTurnStarted($game, $cupidon));

        ProcessCupidonAutoAction::dispatch($this->gameId, $cupidon->id, $this->round)
            ->delay(now()->addSeconds($game->timer('cupidon')));
    }
}
