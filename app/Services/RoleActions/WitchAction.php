<?php

namespace App\Services\RoleActions;

use App\Events\Game\MayorSuccessionStarted;
use App\Events\Game\PlayerEliminated;
use App\Jobs\ProcessMayorSuccession;
use App\Models\Game;
use App\Models\GameAction;
use App\Models\GamePlayer;
use App\Services\PhaseGuard;
use App\Services\PlayerEliminationService;
use App\Services\VoteService;
use Illuminate\Support\Facades\DB;

class WitchAction extends RoleAction
{
    public function __construct(
        private VoteService $voteService,
        private PlayerEliminationService $eliminationService,
    ) {}

    /**
     * Action de la Sorcière : sauver la victime des loups (y compris elle-même), empoisonner un joueur, ou passer son tour.
     * Guard atomique : impossible d'agir deux fois dans le même round (witch_heal/witch_kill/witch_pass).
     * Si la sorcière est la victime des loups et passe ou empoisonne, elle est marquée morte en fin d'action.
     * Si la cible d'un 'kill' est le chasseur, un enregistrement 'hunter_pending' est créé en DB.
     *
     * @param  GamePlayer  $witch    La sorcière (rôle 'witch' requis, doit être vivante)
     * @param  string      $action   Action choisie : 'heal', 'kill' ou 'pass'
     * @param  int|null    $targetId ID du joueur cible (requis pour 'heal' et 'kill', null pour 'pass')
     * @return array{action: string, target: ?GamePlayer} Action effectuée et joueur cible le cas échéant
     * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException (403) si $witch n'est pas la sorcière, est morte, ou s'auto-sauve
     * @throws \Symfony\Component\HttpKernel\Exception\ConflictHttpException     (409) si hors phase nuit, ou action déjà posée ce round
     */
    public function act(GamePlayer $witch, string $action, ?int $targetId): array
    {
        if (! $witch->isWitch()) {
            abort(403, 'Seule la sorcière peut utiliser ce pouvoir.');
        }

        if (! $witch->is_alive) {
            abort(403, 'Un joueur éliminé ne peut pas agir.');
        }

        $game = $witch->game;

        if (! PhaseGuard::canWitchAct($game)) {
            abort(409, 'L\'action de la sorcière n\'est pas disponible hors phase nuit.');
        }

        $witchDiedFromWolves = false;
        $mayorVictim         = null;
        $deferredMayorVictim = null;

        $result = DB::transaction(function () use ($witch, $action, $targetId, $game, &$witchDiedFromWolves, &$mayorVictim, &$deferredMayorVictim) {
            $this->guardNotAlreadyActed($game, $witch->id, ['witch_heal', 'witch_kill', 'witch_pass']);

            $settings = $witch->settings ?? [];
            $target   = null;

            if ($action === 'heal') {
                $victim = $this->voteService->resolveNightVoteFromAction($game);

                if (! $victim) {
                    abort(403, 'Aucune victime à sauver ce round.');
                }

                if ($settings['witch_heal_used'] ?? false) {
                    abort(409, 'Vous avez déjà utilisé votre potion de soin.');
                }

                $victim->update(['is_alive' => true]);
                $settings['witch_heal_used'] = true;
                $witch->update(['settings' => $settings]);

                GameAction::create([
                    'game_id'          => $game->id,
                    'player_id'        => $witch->id,
                    'type'             => 'witch_heal',
                    'target_player_id' => $victim->id,
                    'round'            => $game->round,
                    'phase'            => 'night',
                ]);

                $target = $victim;
            } elseif ($action === 'kill') {
                if ($targetId === $witch->id) {
                    abort(403, 'La sorcière ne peut pas s\'empoisonner elle-même.');
                }

                $target = GamePlayer::where('id', $targetId)
                    ->where('game_id', $game->id)
                    ->where('is_alive', true)
                    ->lockForUpdate()
                    ->first();

                if (! $target) {
                    abort(404, 'Cible invalide.');
                }

                if ($settings['witch_kill_used'] ?? false) {
                    abort(409, 'Vous avez déjà utilisé votre potion de poison.');
                }

                $this->eliminationService->eliminate($target);
                // Priorité chasseur > maire : si la cible est aussi le Chasseur, hunter_pending
                // (créé ci-dessous) gère la succession différée après le tir — ne pas déclencher
                // la succession ici. Voir DECISIONS.md "Chasseur Maire — tir avant succession du maire".
                if ($target->is_mayor && ! $target->isHunter()) {
                    $mayorVictim = $target;
                }
                $settings['witch_kill_used'] = true;
                $witch->update(['settings' => $settings]);

                GameAction::create([
                    'game_id'          => $game->id,
                    'player_id'        => $witch->id,
                    'type'             => 'witch_kill',
                    'target_player_id' => $target->id,
                    'round'            => $game->round,
                    'phase'            => 'night',
                ]);

                if ($target->isHunter()) {
                    GameAction::create([
                        'game_id'   => $game->id,
                        'player_id' => $target->id,
                        'type'      => 'hunter_pending',
                        'round'     => $game->round,
                        'phase'     => 'night',
                    ]);
                }

                // Si la sorcière était la victime des loups et n'a pas utilisé son soin, la marquer morte
                $nightResolveForKill = GameAction::where('game_id', $game->id)
                    ->where('type', 'night_resolve')
                    ->where('round', $game->round)
                    ->first();
                if ($nightResolveForKill
                    && $nightResolveForKill->target_player_id === $witch->id
                    && $witch->is_alive) {
                    $this->eliminationService->eliminate($witch);
                    $witchDiedFromWolves = true;
                }

                // Si la victime résolue est le maire en sursis (ni la sorcière, ni déjà mort)
                if ($nightResolveForKill && $nightResolveForKill->target_player_id !== $witch->id) {
                    $mayorCandidate = GamePlayer::where('id', $nightResolveForKill->target_player_id)
                        ->lockForUpdate()
                        ->first();
                    if ($mayorCandidate?->is_mayor && $mayorCandidate->is_alive) {
                        $this->eliminationService->eliminate($mayorCandidate);

                        // Priorité chasseur > maire : si le maire en sursis est aussi le Chasseur,
                        // créer hunter_pending et NE PAS déclencher la succession ici — la chaîne
                        // ProcessNightEnd → ProcessHunterTurn gère la succession différée après le
                        // tir. Voir DECISIONS.md "Chasseur Maire — tir avant succession du maire".
                        if ($mayorCandidate->isHunter()) {
                            GameAction::create([
                                'game_id'   => $game->id,
                                'player_id' => $mayorCandidate->id,
                                'type'      => 'hunter_pending',
                                'round'     => $game->round,
                                'phase'     => 'night',
                            ]);
                        }

                        $deferredMayorVictim = $mayorCandidate;
                    }
                }
            } else {
                [$witchDiedFromWolves, $deferredMayorVictim] = $this->resolveDeferredVictim($game, $witch);

                GameAction::create([
                    'game_id'          => $game->id,
                    'player_id'        => $witch->id,
                    'type'             => 'witch_pass',
                    'target_player_id' => null,
                    'round'            => $game->round,
                    'phase'            => 'night',
                ]);
            }

            return ['action' => $action, 'target' => $target];
        });

        // Broadcast hors transaction : la sorcière était la victime des loups et n'a pas utilisé son soin,
        // et/ou le maire en sursis (victime initiale des loups, non soignée) a été résolu mort par cette
        // action sorcière (kill ou pass) — jamais les deux à la fois, un seul maire en jeu.
        $this->broadcastDeferredVictim($game, $witch, $witchDiedFromWolves, $deferredMayorVictim);

        // Broadcast hors transaction : le maire était en sursis et n'a pas été soigné
        if ($mayorVictim) {
            $mayorVictim->load('user');
            broadcast(new PlayerEliminated($game, $mayorVictim, 'night_kill'));

            $successionDelay = $mayorVictim->is_inactive ? 0 : $game->timer('mayor_succession');
            broadcast(new MayorSuccessionStarted($game, $mayorVictim->pseudo));
            ProcessMayorSuccession::dispatch($game->id, $game->round, shouldStartNight: true)
                ->delay(now()->addSeconds($successionDelay));
        }

        // Broadcast hors transaction : élimination de la victime nocturne ordinaire différée
        // depuis ProcessNightActions (ni sorcière, ni maire, et soin non utilisé sur elle).
        if ($action !== 'heal') {
            $this->finalizeOrdinaryVictim($game);
        }

        return $result;
    }

    /**
     * Résout, DANS une transaction déjà ouverte par l'appelant, le sort de la sorcière et du
     * maire en sursis lorsque la sorcière n'a pas soigné la victime des loups (kill ou pass,
     * volontaire ou timeout). Lit le `night_resolve` du round pour déterminer si la victime
     * des loups était la sorcière elle-même, ou le maire (ni la sorcière, ni déjà mort).
     *
     * N'élimine JAMAIS une victime ordinaire (ni sorcière, ni maire) — voir finalizeOrdinaryVictim().
     *
     * @return array{0: bool, 1: ?GamePlayer} [$witchDiedFromWolves, $deferredMayorVictim]
     */
    private function resolveDeferredVictim(Game $game, GamePlayer $witch): array
    {
        $witchDiedFromWolves = false;
        $deferredMayorVictim = null;

        $nightResolve = GameAction::where('game_id', $game->id)
            ->where('type', 'night_resolve')
            ->where('round', $game->round)
            ->first();

        // Si la sorcière était la victime des loups et n'a pas été sauvée, la marquer morte maintenant
        if ($nightResolve
            && $nightResolve->target_player_id === $witch->id
            && $witch->is_alive) {
            $this->eliminationService->eliminate($witch);
            $witchDiedFromWolves = true;
        }

        // Si la victime résolue est le maire en sursis (ni la sorcière, ni déjà mort)
        if ($nightResolve && $nightResolve->target_player_id !== $witch->id) {
            $mayorCandidate = GamePlayer::where('id', $nightResolve->target_player_id)
                ->lockForUpdate()
                ->first();
            if ($mayorCandidate?->is_mayor && $mayorCandidate->is_alive) {
                $this->eliminationService->eliminate($mayorCandidate);

                // Priorité chasseur > maire : si le maire en sursis est aussi le Chasseur,
                // créer hunter_pending et NE PAS déclencher la succession ici — la chaîne
                // ProcessNightEnd → ProcessHunterTurn gère la succession différée après le
                // tir. Voir DECISIONS.md "Chasseur Maire — tir avant succession du maire".
                if ($mayorCandidate->isHunter()) {
                    GameAction::create([
                        'game_id'   => $game->id,
                        'player_id' => $mayorCandidate->id,
                        'type'      => 'hunter_pending',
                        'round'     => $game->round,
                        'phase'     => 'night',
                    ]);
                }

                $deferredMayorVictim = $mayorCandidate;
            }
        }

        return [$witchDiedFromWolves, $deferredMayorVictim];
    }

    /**
     * Broadcast HORS transaction du résultat de resolveDeferredVictim() : PlayerEliminated
     * pour la sorcière et/ou le maire en sursis, puis succession du maire si nécessaire
     * (sauf priorité Chasseur > Maire, gérée par hunter_pending déjà créé).
     */
    private function broadcastDeferredVictim(Game $game, GamePlayer $witch, bool $witchDiedFromWolves, ?GamePlayer $deferredMayorVictim): void
    {
        if ($witchDiedFromWolves) {
            $witch->load('user');
            broadcast(new PlayerEliminated($game, $witch, 'night_kill'));

            // Si la sorcière était aussi le maire, déclencher la succession maintenant
            if ($witch->is_mayor) {
                $successionDelay = $witch->is_inactive ? 0 : $game->timer('mayor_succession');
                broadcast(new MayorSuccessionStarted($game, $witch->pseudo));
                ProcessMayorSuccession::dispatch($game->id, $game->round, shouldStartNight: true)
                    ->delay(now()->addSeconds($successionDelay));
            }
        }

        if ($deferredMayorVictim) {
            $deferredMayorVictim->load('user');
            broadcast(new PlayerEliminated($game, $deferredMayorVictim, 'night_kill'));

            if (! $deferredMayorVictim->isHunter()) {
                $successionDelay = $deferredMayorVictim->is_inactive ? 0 : $game->timer('mayor_succession');
                broadcast(new MayorSuccessionStarted($game, $deferredMayorVictim->pseudo));
                ProcessMayorSuccession::dispatch($game->id, $game->round, shouldStartNight: true)
                    ->delay(now()->addSeconds($successionDelay));
            }
        }
    }

    /**
     * Élimine et broadcast, HORS transaction, la victime nocturne ordinaire (ni sorcière,
     * ni maire) toujours vivante d'après le `night_resolve` du round — cas où la sorcière
     * n'a pas soigné (kill, pass volontaire, ou timeout via ProcessWitchAutoAction).
     */
    private function finalizeOrdinaryVictim(Game $game): void
    {
        $nightResolve = GameAction::where('game_id', $game->id)
            ->where('type', 'night_resolve')
            ->where('round', $game->round)
            ->first();

        $ordinaryVictim = null;
        if ($nightResolve) {
            $candidate = GamePlayer::find($nightResolve->target_player_id);
            if ($candidate
                && $candidate->is_alive
                && ! $candidate->isWitch()
                && ! $candidate->is_mayor) {
                $ordinaryVictim = $candidate;
            }
        }

        if ($ordinaryVictim) {
            $this->eliminationService->eliminate($ordinaryVictim);
            $ordinaryVictim->load('user');
            broadcast(new PlayerEliminated($game, $ordinaryVictim, 'night_kill'));

            if ($ordinaryVictim->isHunter()) {
                GameAction::create([
                    'game_id'   => $game->id,
                    'player_id' => $ordinaryVictim->id,
                    'type'      => 'hunter_pending',
                    'round'     => $game->round,
                    'phase'     => 'night',
                ]);
            }
        }
    }

    /**
     * Point d'entrée pour ProcessWitchAutoAction (timeout) : reproduit exactement la
     * finalisation de la branche 'pass' de act() (resolveDeferredVictim dans sa propre
     * transaction, puis les broadcasts hors transaction), pour le cas où la Sorcière
     * n'a pas cliqué avant l'expiration de son timer. À appeler UNIQUEMENT après avoir
     * créé le GameAction witch_pass du round (guard anti-doublon déjà vérifié par
     * l'appelant), jamais si une action sorcière existait déjà pour ce round.
     */
    public function finalizeTimedOutVictim(Game $game, GamePlayer $witch): void
    {
        [$witchDiedFromWolves, $deferredMayorVictim] = DB::transaction(
            fn () => $this->resolveDeferredVictim($game, $witch)
        );

        $this->broadcastDeferredVictim($game, $witch, $witchDiedFromWolves, $deferredMayorVictim);

        $this->finalizeOrdinaryVictim($game);
    }
}
