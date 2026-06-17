<?php

namespace App\Jobs;

use App\Events\Game\WitchTurnStarted;
use App\Models\Game;
use App\Services\PhaseGuard;
use App\Services\VoteService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Démarre le tour de la Sorcière après résolution du vote des loups.
 *
 * Seul dispatcher légitime : ProcessNightActions::handle() (Guard #4 RISK_GUARDS).
 * Ne jamais dispatcher depuis ProcessWerewolvesTurn ni ProcessSeerAutoAction.
 *
 * Applique les 3 cas du Guard #3 révisé (fix/witch-turn-no-victim, RISK_GUARDS) :
 *   (a) Deux potions épuisées → skip silencieux (return).
 *   (b) Pas de victime + poison épuisé → skip silencieux (return).
 *   (c) Tous les autres cas → broadcast WitchTurnStarted + dispatch ProcessWitchAutoAction.
 *
 * ProcessNightEnd est toujours dispatché par ProcessNightActions avec un délai buffer
 * couvrant le tour sorcière — les skips silencieux ne dispatchent pas ProcessNightEnd.
 */
class ProcessWitchTurn implements ShouldQueue
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
     * Applique les guards et broadcast WitchTurnStarted si la sorcière peut agir.
     *
     * Guards d'entrée :
     *   - La partie existe.
     *   - status IN ('night', 'processing_night').
     *   - round === $this->round.
     *   - La sorcière est vivante.
     *   - Guard #6 : aucune action sorcière (witch_heal/witch_kill/witch_pass) ce round.
     *   - Guard #3 révisé : skip si pas de victime et potions incompatibles (voir ci-dessus).
     *
     * Dispatche :
     *   - ProcessWitchAutoAction($gameId, $round)::delay(witch_timer).
     */
    public function handle(VoteService $voteService): void
    {
        $game = Game::find($this->gameId);

        if (! $game || $game->round !== $this->round || ! PhaseGuard::isNightOrProcessing($game)) {
            return;
        }

        $witch = $game->players()->where('role', 'witch')->where('is_alive', true)->first();

        if (! $witch) {
            return;
        }

        // Guard #6 : sorcière a déjà agi ce round (ne re-déclenche pas le tour)
        $alreadyActed = $game->actions()
            ->where('round', $this->round)
            ->whereIn('type', ['witch_heal', 'witch_kill', 'witch_pass'])
            ->exists();

        if ($alreadyActed) {
            return;
        }

        // Guard #3 (révisé) : égalité chez les loups -> pas de victime à sauver
        $victim = $voteService->resolveNightVote($game);

        $witchSettings = $witch->settings ?? [];
        $healUsed      = $witchSettings['witch_heal_used'] ?? false;
        $killUsed      = $witchSettings['witch_kill_used'] ?? false;

        // Pas de victime ET les deux potions sont épuisées -> skip silencieux.
        // ProcessNightEnd est déjà dispatché par ProcessNightActions (délai
        // couvrant le tour sorcière) — il gère la fin de nuit dans tous les cas.
        if (! $victim && $healUsed && $killUsed) {
            return;
        }

        $healAvailable = ! $healUsed && $victim !== null && $victim->id !== $witch->id;
        $killAvailable = ! $killUsed;

        // Pas de victime ET pas de poison disponible -> skip silencieux. Idem.
        if (! $victim && ! $killAvailable) {
            return;
        }

        broadcast(new WitchTurnStarted($game, $witch, $victim, $healAvailable, $killAvailable));

        ProcessWitchAutoAction::dispatch($this->gameId, $this->round)
            ->delay(now()->addSeconds($game->timer('witch')));
    }
}
