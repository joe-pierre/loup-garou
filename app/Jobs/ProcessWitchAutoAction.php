<?php

namespace App\Jobs;

use App\Models\Game;
use App\Models\GameAction;
use App\Services\PhaseGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Action automatique de la Sorcière après expiration du timer.
 *
 * Dispatché par ProcessWitchTurn avec un délai égal au timer sorcière.
 *
 * Seul dispatcher légitime de ProcessNightEnd::delay(0) dans le chemin principal :
 * ProcessNightActions dispatche ProcessNightEnd avec un long délai buffer, mais
 * c'est ce job qui déclenche la clôture immédiate après le timer sorcière complet.
 * Voir DECISIONS.md "ProcessWitchTurn guards skip — double ProcessNightEnd cassait
 * la séquence nocturne".
 */
class ProcessWitchAutoAction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param int $gameId Identifiant de la partie.
     * @param int $round  Round de référence (idempotence).
     */
    public function __construct(
        public readonly int $gameId,
        public readonly int $round,
    ) {}

    /**
     * Crée un witch_pass si la sorcière n'a pas encore agi, puis déclenche la fin de nuit.
     *
     * Guards d'entrée :
     *   - La partie existe.
     *   - round === $this->round.
     *   - status IN ('night', 'processing_night').
     *
     * Si aucune action sorcière (witch_heal/witch_kill/witch_pass) n'existe pour ce round,
     * crée un GameAction de type witch_pass.
     *
     * Dispatche :
     *   - ProcessNightEnd($gameId, $round)::delay(0) — toujours.
     */
    public function handle(): void
    {
        $game = Game::find($this->gameId);

        if (! $game || $game->round !== $this->round || ! PhaseGuard::isNightOrProcessing($game)) {
            return; // idempotent : déjà transitionné
        }

        $alreadyActed = $game->actions()
            ->where('round', $this->round)
            ->whereIn('type', ['witch_heal', 'witch_kill', 'witch_pass'])
            ->exists();

        if (! $alreadyActed) {
            $witch = $game->players()->where('role', 'witch')->where('is_alive', true)->first();

            if ($witch) {
                GameAction::create([
                    'game_id'          => $game->id,
                    'player_id'        => $witch->id,
                    'type'             => 'witch_pass',
                    'target_player_id' => null,
                    'round'            => $this->round,
                    'phase'            => 'night',
                ]);
            }
        }

        ProcessNightEnd::dispatch($this->gameId, $this->round)->delay(0);
    }
}
