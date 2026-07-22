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
 * Branché dans PhaseManager::startNight() (SPEC_CUPIDON.md §8, tâche 4) : dispatché
 * à la place de ProcessSeerTurn quand round === 1 et qu'un Cupidon est distribué.
 *
 * Si Cupidon mort/absent/inactif ou composition sans Cupidon → aucun effet Cupidon,
 * mais la nuit doit continuer : dispatch immédiat de ProcessSeerTurn (même principe
 * que ProcessSeerTurn lui-même quand la Voyante est morte/absente/inactive).
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

        // Pas de Cupidon dans la composition, mort, ou inactif → la nuit continue
        // sans lui, la Voyante démarre immédiatement (délai night_start_delay déjà écoulé).
        if (! $cupidon || $cupidon->is_inactive) {
            ProcessSeerTurn::dispatch($this->gameId, $this->round);
            return;
        }

        broadcast(new CupidonTurnStarted($game, $cupidon));

        ProcessCupidonAutoAction::dispatch($this->gameId, $cupidon->id, $this->round)
            ->delay(now()->addSeconds($game->timer('cupidon')));
    }
}
