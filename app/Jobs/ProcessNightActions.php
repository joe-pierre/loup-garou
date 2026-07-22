<?php

namespace App\Jobs;

use App\Events\Game\MayorSuccessionStarted;
use App\Events\Game\PlayerEliminated;
use App\Models\Game;
use App\Models\GameAction;
use App\Notifications\PlayerKilledNightNotification;
use App\Services\PlayerEliminationService;
use App\Services\VoteService;
use App\Services\WinConditionChecker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Résout le vote des loups et orchestre la suite de la séquence nocturne.
 *
 * Dispatché par ProcessWerewolvesTurn après expiration du timer loups.
 *
 * Ordre d'exécution dans handle() :
 *   1. Résolution du vote loups (resolveNightVote) → élimination victime.
 *   2. Broadcast PlayerEliminated + notification push si victime.
 *   3. Création de GameAction hunter_pending en DB si la victime est le Chasseur.
 *   4. Vérification condition de victoire (WinConditionChecker) → return si finie.
 *   5. Dispatch ProcessWitchTurn::delay(0) si sorcière vivante.
 *   6. Dispatch ProcessMayorSuccession(shouldStartNight:true) si le maire est tué —
 *      SAUF si la victime est le Chasseur (hunter_pending) : priorité tir > succession,
 *      mutuellement exclusif (voir DECISIONS.md "Chasseur Maire — tir avant succession").
 *   7. Dispatch ProcessNightEnd::delay(witch_timer + mayor_succession + 5s) — toujours.
 */
class ProcessNightActions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param int $gameId Identifiant de la partie.
     * @param int $round  Round de référence (double-fire guard).
     */
    public function __construct(
        public readonly int $gameId,
        public readonly int $round,
    ) {}

    /**
     * Résout la nuit et dispatche les jobs de continuation.
     *
     * Guards d'entrée (dans la transaction lockForUpdate) :
     *   - La partie existe avec status IN ('night', 'wolves_turn').
     *   - round === $this->round.
     *
     * Le statut passe à 'processing_night' dans la transaction avant toute action.
     * Les rejets par le guard sont loggués en warning pour faciliter le débogage.
     *
     * Dispatche :
     *   - ProcessWitchTurn($gameId, $round)::delay(0) si sorcière vivante.
     *   - ProcessMayorSuccession($gameId, $round, shouldStartNight:true) si maire tué.
     *   - ProcessNightEnd($gameId, $round)::delay(witch_timer + mayor_succession + 5s) — toujours.
     */
    public function handle(VoteService $voteService, WinConditionChecker $winChecker): void
    {
        $game = null;

        DB::transaction(function () use (&$game) {
            $locked = Game::where('id', $this->gameId)
                ->whereIn('status', ['night', 'wolves_turn'])
                ->where('round', $this->round)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return;
            }

            $locked->update(['status' => 'processing_night']);
            $game = $locked;
        });

        if (! $game) {
            Log::warning('ProcessNightActions: rejeté par le guard (statut ou round incorrect)', [
                'game_id' => $this->gameId,
                'round'   => $this->round,
            ]);
            return;
        }

        $victim = $voteService->resolveNightVote($game);

        // Résoudre la sorcière maintenant pour les deux guards de sursis (sorcière et maire).
        $witch = $game->players()->where('role', 'witch')->where('is_alive', true)->first();

        $victimIsWitchWithHeal          = false;
        $victimIsMayorWithWitchAvailable = false;
        $hunterPending                   = false;

        if ($victim) {
            // Si la victime est la sorcière avec sa potion de soin disponible,
            // ne pas la marquer morte immédiatement : elle gérera elle-même dans witchAct().
            $victimIsWitchWithHeal = $victim->isWitch()
                && $victim->is_alive
                && ! ($victim->settings['witch_heal_used'] ?? false);

            // Si la victime est le maire et que la sorcière peut encore soigner,
            // différer la mort et la succession — c'est witchAct() qui les déclenchera.
            $victimIsMayorWithWitchAvailable = $victim->is_mayor
                && $witch !== null
                && ! ($witch->settings['witch_heal_used'] ?? false);

            // Si la sorcière peut encore soigner (quelle que soit la victime),
            // différer PlayerEliminated — la sorcière décide au moment de son action.
            $witchCanSaveVictim = $witch !== null
                && ! ($witch->settings['witch_heal_used'] ?? false);

            if (! $victimIsWitchWithHeal && ! $victimIsMayorWithWitchAvailable && ! $witchCanSaveVictim) {
                app(PlayerEliminationService::class)->eliminate($victim);
            }

            // Persiste la victime résolue pour les jobs suivants (ProcessWitchTurn,
            // witchAct). Pattern identique à hunter_pending. Créé dans tous les cas.
            GameAction::create([
                'game_id'          => $game->id,
                'player_id'        => $victim->id,
                'type'             => 'night_resolve',
                'target_player_id' => $victim->id,
                'round'            => $game->round,
                'phase'            => 'night',
            ]);

            if (! $victimIsWitchWithHeal && ! $victimIsMayorWithWitchAvailable && ! $witchCanSaveVictim) {
                // Charger user avant le broadcast pour google_name dans PlayerEliminated
                $victim->load('user');
                broadcast(new PlayerEliminated($game, $victim, 'night_kill'));

                if ($victim->isHunter()) {
                    $hunterPending = true;

                    GameAction::create([
                        'game_id'   => $game->id,
                        'player_id' => $victim->id,
                        'type'      => 'hunter_pending',
                        'round'     => $game->round,
                        'phase'     => 'night',
                    ]);
                }

                try {
                    $victim->user->notify(new PlayerKilledNightNotification());
                } catch (\Throwable) {}
            }
        }

        if ($winChecker->check($game)) {
            return;
        }

        if ($witch) {
            ProcessWitchTurn::dispatch($game->id, $game->round)->delay(0);
        }

        // Priorité chasseur > maire : si la victime est le Chasseur (hunter_pending créé
        // ci-dessus), le tir doit avoir lieu AVANT la succession — ProcessNightEnd →
        // ProcessHunterTurn → ProcessHunterAutoAction reçoit déjà $isMayor et déclenche
        // la succession différée après le tir (ou le renoncement). Voir DECISIONS.md
        // "Chasseur Maire — tir avant succession du maire".
        if ($hunterPending) {
            // Rien à faire ici : la chaîne hunter_pending gère la succession.
        } elseif ($victim?->is_mayor && ! $victimIsMayorWithWitchAvailable) {
            $successionDelay = $victim->is_inactive
                ? 0
                : $game->timer('mayor_succession');

            broadcast(new MayorSuccessionStarted($game, $victim->pseudo));
            ProcessMayorSuccession::dispatch($game->id, $game->round, shouldStartNight: true)
                ->delay(now()->addSeconds($successionDelay));
        }

        // Le délai doit couvrir le tour de la sorcière (witch_timer) en plus
        // du buffer de succession, sinon ProcessNightEnd coupe son tour.
        $witchTimer       = $witch ? $game->timer('witch') : 0;
        $successionBuffer = $game->timer('mayor_succession') + 5;

        ProcessNightEnd::dispatch($game->id, $game->round)
            ->delay(now()->addSeconds($witchTimer + $successionBuffer));
    }
}