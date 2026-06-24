<?php

namespace App\Jobs;

use App\Events\Game\WerewolvesTurnStarted;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Démarre le tour des loups-garous.
 *
 * Dispatché par ProcessSeerTurn après expiration du timer voyante (delay=seer+2),
 * ou immédiatement si la voyante est inactive/morte, ou par ActionController
 * quand la voyante agit manuellement.
 *
 * Change le statut night → wolves_turn dans une transaction DB avec lockForUpdate
 * pour éviter les doubles déclenchements, puis dispatche ProcessNightActions
 * avec un délai égal au timer loups.
 */
class ProcessWerewolvesTurn implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param int $gameId Identifiant de la partie.
     * @param int $round  Round de référence (guard anti-stale-job inter-rounds).
     */
    public function __construct(
        public readonly int $gameId,
        public readonly int $round,
    ) {}

    /**
     * Transition atomique night → wolves_turn, broadcast WerewolvesTurnStarted,
     * dispatche ProcessNightActions(delay=wolves_timer).
     *
     * Guards d'entrée (dans la transaction lockForUpdate) :
     *   - La partie existe avec status === 'night'.
     *   - round === $this->round (rejette les jobs stale d'une nuit précédente).
     *
     * La transition status → wolves_turn est effectuée dans DB::transaction
     * avec lockForUpdate pour garantir l'idempotence.
     * Les rejets par le guard sont loggués en warning pour faciliter le débogage.
     *
     * Dispatche :
     *   - ProcessNightActions($gameId, $round)::delay(wolves_timer).
     */
    public function handle(): void
    {
        $game = null;
        $wolvesTimer = 0;
        $round = 0;

        DB::transaction(function () use (&$game, &$wolvesTimer, &$round) {
            $locked = Game::where('id', $this->gameId)
                ->where('status', 'night')
                ->where('round', $this->round)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return;
            }

            $wolvesTimer = $locked->timer('werewolves');
            $round       = $locked->round;

            $locked->update([
                'status'         => 'wolves_turn',
                'phase_deadline' => now()->addSeconds($wolvesTimer),
            ]);

            $game = $locked;
        });

        if (! $game) {
            Log::warning('ProcessWerewolvesTurn: rejeté par le guard (statut ou round incorrect)', [
                'game_id' => $this->gameId,
                'round'   => $this->round,
            ]);
            return;
        }

        $eligibleTargets = $game->alivePlayers()
            ->whereNotIn('role', ['werewolf', 'white_wolf'])
            ->get()
            ->map(fn (GamePlayer $p) => ['id' => $p->id, 'pseudo' => $p->pseudo])
            ->values()
            ->toArray();

        broadcast(new WerewolvesTurnStarted($game, $eligibleTargets));

        ProcessNightActions::dispatch($this->gameId, $round)
            ->delay(now()->addSeconds($wolvesTimer));
    }
}