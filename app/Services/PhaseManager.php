<?php

namespace App\Services;

use App\Events\Game\DayStarted;
use App\Events\Game\NightStarted;
use App\Events\Game\PhaseAnnouncement;
use App\Jobs\ProcessDayVote;
use App\Jobs\ProcessSeerTurn;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Services\PhaseGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Gère les transitions de phases d'une partie : début de jour, début de nuit, fin de nuit.
 *
 * Règles architecturales critiques :
 * - Broadcasts et dispatches de jobs TOUJOURS hors des transactions lockForUpdate
 *   (cf. DECISIONS.md "Phase nuit bloquée — broadcast synchrone dans DB::transaction").
 * - startDay() est appelé par endNight(), jamais directement depuis un lockForUpdate().
 * - Chaque méthode garde son propre guard transactionnel (lockForUpdate + canTransition)
 *   pour être appelable depuis un Job isolé ou depuis une transaction parent (savepoints MySQL).
 * - canTransition() ne couvre que les statuts canoniques du Workflow : 'processing_night'
 *   et 'processing_day' bypassent le guard et passent directement à whereIn().
 */
class PhaseManager
{
    /**
     * Passe la partie en phase jour : transition 'night'/'processing_night' → 'day'.
     * Broadcaste DayStarted et dispatche ProcessDayVote avec le délai du timer 'day_vote'.
     * Guard atomique : no-op si la partie n'est plus en statut nuit.
     *
     * Appel hors lockForUpdate uniquement — jamais depuis l'intérieur d'une transaction verrouillée.
     *
     * @param  Game            $game          La partie à transitionner
     * @param  GamePlayer|null $victim        Victime nocturne (null si égalité des loups ou sauvée par sorcière)
     * @param  bool            $witchActed    Vrai si la sorcière a utilisé soin ou poison ce round
     * @param  int|null        $savedPlayerId ID du joueur sauvé par la sorcière (null si pas de soin)
     * @return void
     */
    public function startDay(
        Game $game,
        ?GamePlayer $victim,
        bool $witchActed = false,
        ?int $savedPlayerId = null,
    ): void {
        $game->refresh();
        $locked = null;
        $timer  = $game->timer('day_vote');

        DB::transaction(function () use ($game, &$locked, $timer) {
            $locked = Game::where('id', $game->id)
                ->whereIn('status', ['night', 'processing_night'])
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return;
            }

            // canTransition() ne connaît que les statuts canoniques du Workflow :
            // 'processing_night' (Tâche E-H) reste hors de son périmètre et bypasse le guard.
            if ($locked->status === 'night' && ! $locked->canTransition('start_day')) {
                Log::warning("Transition 'start_day' refusée depuis status={$locked->status}");
                return;
            }

            $locked->update([
                'status'         => 'day',
                'phase_deadline' => now()->addSeconds($timer),
            ]);
        });

        if (! $locked) {
            return;
        }

        broadcast(new PhaseAnnouncement($locked->id, 'day_break', "L'aube approche\u{2026}", 4000));
        broadcast(new DayStarted($locked, $victim, $witchActed, $savedPlayerId));
        ProcessDayVote::dispatch($locked->id, $locked->round)
            ->delay(now()->addSeconds($timer));
    }

    /**
     * Passe la partie en phase nuit : transition 'day'/'processing_day' → 'night', incrémente le round.
     * Broadcaste NightStarted et dispatche ProcessSeerTurn avec le délai 'night_start_delay'.
     * Guard atomique : no-op si la partie n'est plus en statut jour.
     *
     * Appel hors lockForUpdate uniquement — jamais depuis l'intérieur d'une transaction verrouillée.
     *
     * @param  Game $game La partie à transitionner
     * @return void
     */
    public function startNight(Game $game): void
    {
        $game->refresh();
        $locked = null;
        $timer  = $game->timer('seer');

        DB::transaction(function () use ($game, &$locked, $timer) {
            $locked = Game::where('id', $game->id)
                ->whereIn('status', ['day', 'processing_day'])
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return;
            }

            // canTransition() ne connaît que les statuts canoniques du Workflow :
            // 'processing_day' (Tâches F-G) reste hors de son périmètre et bypasse le guard.
            if ($locked->status === 'day' && ! $locked->canTransition('continue_night')) {
                Log::warning("Transition 'continue_night' refusée depuis status={$locked->status}");
                return;
            }

            $locked->update([
                'status'         => 'night',
                'round'          => $locked->round + 1,
                'phase_deadline' => now()->addSeconds($timer),
            ]);
        });

        if (! $locked) {
            return;
        }

        broadcast(new PhaseAnnouncement($locked->id, 'night_fall', "Le village s'endort\u{2026}", 4000));
        broadcast(new NightStarted($locked));
        ProcessSeerTurn::dispatch($locked->id, $locked->round)
            ->delay(now()->addSeconds($locked->timer('night_start_delay')));
    }

    /**
     * Résout la fin de nuit : détermine la victime du vote des loups, vérifie si elle a été
     * sauvée par la sorcière, collecte les données de sorcière pour DayStarted, puis appelle startDay().
     * Vérifie les conditions de victoire avant la transition — no-op si la partie est déjà terminée.
     * Guard de statut : no-op si le statut n'est pas 'night' ou 'processing_night'.
     *
     * @param  Game $game La partie dont la nuit se termine
     * @return void
     */
    public function endNight(Game $game): void
    {
        $game->refresh();

        if (! PhaseGuard::isNightOrProcessing($game)) {
            return;
        }

        if (app(WinConditionChecker::class)->check($game)) {
            return;
        }

        $victim = app(VoteService::class)->resolveNightVote($game);

        if ($victim && $victim->is_alive) {
            $victim = null; // sauvé par la sorcière
        }

        // Vérifier si la sorcière a agi (heal ou kill) ce round
        $witchActed = $game->actions()
            ->where('round', $game->round)
            ->whereIn('type', ['witch_heal', 'witch_kill'])
            ->exists();

        // Récupérer l'id du joueur sauvé par la sorcière ce round (si applicable)
        $savedPlayerId = null;
        if ($witchActed) {
            $healAction = $game->actions()
                ->where('round', $game->round)
                ->where('type', 'witch_heal')
                ->first();
            $savedPlayerId = $healAction?->target_player_id;
        }

        $this->startDay($game, $victim, $witchActed, $savedPlayerId);
    }
}