<?php

namespace App\Jobs;

use App\Events\Game\MayorSuccessionDone;
use App\Events\Game\PhaseAnnouncement;
use App\Models\Game;
use App\Models\GameAction;
use App\Services\PhaseManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Désigne un successeur aléatoire au maire éliminé.
 *
 * Dispatché par ProcessNightActions (mort la nuit, shouldStartNight=true)
 * ou par VoteService::resolveDayVote() (mort le jour, shouldStartNight=false, défaut).
 *
 * ⚠️ Le flag shouldStartNight mémorise le contexte du dispatch : sans lui, une
 * race condition entre ProcessMayorSuccession et ProcessNightEnd pourrait faire lire
 * un statut 'day' (déjà transitionné par ProcessNightEnd) et appeler startNight() à tort.
 * Voir DECISIONS.md "ProcessMayorSuccession — flag shouldStartNight".
 *
 * Quand shouldStartNight=true → return après broadcast MayorSuccessionDone :
 * ProcessNightEnd (déjà dispatché avec délai buffer) termine la nuit.
 * Quand shouldStartNight=false → appelle PhaseManager::startNight() après la succession.
 */
class ProcessMayorSuccession implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param int  $gameId          Identifiant de la partie.
     * @param int  $round           Round de référence (double-fire guard).
     * @param bool $shouldStartNight true si la succession a lieu la nuit
     *                               (ProcessNightEnd prend le relais),
     *                               false si pendant le jour (ce job appelle startNight()).
     */
    public function __construct(
        public readonly int $gameId,
        public readonly int $round,
        public readonly bool $shouldStartNight = false,
    ) {}

    /**
     * Désigne le successeur dans une transaction atomique, broadcast MayorSuccessionDone.
     *
     * Guards d'entrée :
     *   - La partie existe.
     *   - status IN ('night', 'processing_night', 'day', 'processing_day').
     *   - round === $this->round.
     *   - Aucun GameAction de type 'mayor_succession' pour ce round + phase (idempotence).
     *
     * Le broadcast MayorSuccessionDone est émis HORS transaction (après commit).
     *
     * Si shouldStartNight=true → return après broadcast (ProcessNightEnd gère la suite).
     * Si shouldStartNight=false → appelle PhaseManager::startNight() après $game->refresh().
     */
    public function handle(PhaseManager $phaseManager): void
    {
        $game = Game::find($this->gameId);

        // Accepter night, processing_night, day ET processing_day
        // PhaseGuard ne couvre pas ce cas : union de isNightOrProcessing + isDay (ensemble unique à ce job)
        if (! $game || ! in_array($game->status, ['night', 'processing_night', 'day', 'processing_day']) || $game->round !== $this->round) {
            return;
        }

        $phaseToStart = $this->shouldStartNight ? 'night' : 'day';

        // La transaction retourne les données nécessaires au broadcast
        // mais ne broadcaste pas — le broadcast doit être HORS transaction
        $result = DB::transaction(function () use ($game, $phaseToStart) {
            $locked = Game::where('id', $game->id)
                ->whereIn('status', ['night', 'processing_night', 'day', 'processing_day'])
                ->where('round', $this->round)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return null;
            }

            $alreadyDone = GameAction::where('game_id', $locked->id)
                ->where('type', 'mayor_succession')
                ->where('round', $locked->round)
                ->where('phase', $phaseToStart)
                ->lockForUpdate()
                ->exists();

            if ($alreadyDone) {
                return null;
            }

            $deadMayor = $locked->players()->where('is_mayor', true)->first();

            $successor = $locked->alivePlayers()->inRandomOrder()->first();

            if (! $successor) {
                return null;
            }

            // Retirer le flag maire à tous les joueurs de la partie avant de
            // l'assigner au successeur (idempotent, indépendant de l'état actuel)
            $locked->players()->update(['is_mayor' => false]);
            $successor->update(['is_mayor' => true]);

            GameAction::create([
                'game_id'          => $locked->id,
                'player_id'        => $deadMayor?->id ?? $successor->id,
                'type'             => 'mayor_succession',
                'target_player_id' => $successor->id,
                'round'            => $locked->round,
                'phase'            => $phaseToStart,
            ]);

            // NE PAS broadcaster ici — la transaction n'est pas encore committée
            return ['game' => $locked, 'successor' => $successor];
        });

        if (! $result) {
            return;
        }

        // Broadcast APRÈS commit de la transaction
        broadcast(new PhaseAnnouncement($result['game']->id, 'mayor_succession', 'Le Maire a succombé. Un nouveau va prendre sa place.', 4000));
        broadcast(new MayorSuccessionDone($result['game'], $result['successor'], true));

        if ($phaseToStart === 'night') {
            // Succession déclenchée la nuit : ne pas démarrer de nouvelle phase ici.
            // La fin de nuit est gérée par ProcessNightEnd (Tâche H) avec un délai buffer.
            return;
        }

        $game->refresh();
        $phaseManager->startNight($game);
    }
}