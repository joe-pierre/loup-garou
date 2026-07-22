<?php

namespace App\Services;

use App\Events\Game\GameFinished;
use App\Events\Game\GameStarted;
use App\Events\Game\PhaseAnnouncement;
use App\Events\Game\PlayerEliminated;
use App\Notifications\PlayerExcludedNotification;
use App\Notifications\RoleAssignedNotification;
use App\Events\Game\MayorElectionStarted;
use App\Events\Game\PlayerDisconnected;
use App\Events\Game\PlayerExcluded;
use App\Events\Game\PlayerJoined;
use App\Events\Game\PlayerReady;
use App\Events\Game\PlayerReconnected;
use App\Jobs\CheckReconnectionTimeout;
use App\Jobs\ProcessMayorElection;
use App\Jobs\WaitForReadyPlayers;
use App\Services\PhaseGuard;
use App\Services\TimerCalculator;
use App\Models\Exclusion;
use App\Models\Game;
use App\Models\GameAction;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Service d'orchestration principal : création, démarrage, gestion des joueurs,
 * actions spéciales (voyante, sorcière, chasseur) et gestion du cycle de vie d'une partie.
 *
 * Tous les guards métier (phase, rôle, vivant) sont vérifiés dans ce service.
 * Les broadcasts et dispatches de jobs se font hors des transactions lockForUpdate.
 */
class GameService
{
    public function __construct(
        private RoleDistributor $roleDistributor,
        private PhaseManager $phaseManager,
    ) {}

    /**
     * Crée une nouvelle partie et y inscrit le créateur comme host.
     *
     * @param  User   $user       Utilisateur créateur, devient host
     * @param  string $pseudo     Pseudo affiché en partie
     * @param  int    $maxPlayers Nombre maximum de joueurs (déclencheur du démarrage automatique)
     * @return Game               La partie créée avec statut 'waiting'
     */
    public function createGame(User $user, string $pseudo, int $maxPlayers): Game
    {
        $code = $this->generateUniqueCode();

        return DB::transaction(function () use ($user, $pseudo, $maxPlayers, $code) {
            $game = Game::create([
                'code'        => $code,
                'status'      => 'waiting',
                'max_players' => $maxPlayers,
            ]);

            GamePlayer::create([
                'game_id'   => $game->id,
                'user_id'   => $user->id,
                'pseudo'    => $pseudo,
                'is_host'   => true,
                'joined_at' => now(),
            ]);

            return $game;
        });
    }

    /**
     * Inscrit un utilisateur dans une partie existante par son code.
     * Déclenche automatiquement startGame() si la partie atteint max_players.
     * Si l'utilisateur est déjà inscrit, retourne son GamePlayer existant.
     *
     * @param  User   $user   Utilisateur qui rejoint
     * @param  string $code   Code de la partie (6 caractères majuscules)
     * @param  string $pseudo Pseudo affiché en partie
     * @return GamePlayer      Le GamePlayer créé ou existant
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException     (404) si la partie n'existe pas
     * @throws \Symfony\Component\HttpKernel\Exception\ConflictHttpException     (409) si la partie a déjà commencé ou est pleine
     * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException (403) si l'utilisateur est exclu
     */
    public function joinGame(User $user, string $code, string $pseudo): GamePlayer
    {
        $result = DB::transaction(function () use ($user, $code, $pseudo) {
            $game = Game::where('code', $code)->lockForUpdate()->first();

            if (! $game) {
                abort(404, 'Partie introuvable.');
            }

            if ($game->status !== 'waiting') {
                abort(409, 'Cette partie a déjà commencé.');
            }

            // Vérifier si l'utilisateur est déjà dans la partie
            $existing = GamePlayer::where('game_id', $game->id)
                ->where('user_id', $user->id)
                ->first();

            if ($existing) {
                return ['player' => $existing, 'broadcast' => false, 'start' => false, 'game' => null];
            }

            if ($game->players()->count() >= $game->max_players) {
                abort(409, 'Cette partie est déjà complète.');
            }

            $excluded = Exclusion::where('game_id', $game->id)
                ->where('user_id', $user->id)
                ->exists();

            if ($excluded) {
                abort(403, 'Tu as été exclu de cette partie.');
            }

            $player = GamePlayer::create([
                'game_id'   => $game->id,
                'user_id'   => $user->id,
                'pseudo'    => $pseudo,
                'joined_at' => now(),
            ]);

            $currentCount = $game->players()->count();
            $start        = $currentCount === $game->max_players;

            return [
                'player'    => $player,
                'broadcast' => true,
                'start'     => $start,
                'game'      => $game,
            ];
        });

        if ($result['broadcast']) {
            broadcast(new PlayerJoined($result['game'], $result['player']));
        }

        if ($result['start']) {
            $this->startGame($result['game']);
        }

        return $result['player'];
    }

    /**
     * Démarre la partie : distribue les rôles, notifie chaque joueur via canal privé, lance WaitForReadyPlayers.
     * Guard atomique (lockForUpdate + canTransition) : no-op si la partie n'est plus en 'waiting'.
     *
     * @param  Game $game La partie à démarrer (statut attendu : 'waiting')
     * @return void
     */
    public function startGame(Game $game): void
    {
        $data = DB::transaction(function () use ($game) {
            // Re-lock et vérifier le status pour éviter un double démarrage
            $locked = Game::where('id', $game->id)
                ->where('status', 'waiting')
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return null;
            }

            if (! $locked->canTransition('start_election')) {
                Log::warning("Transition 'start_election' refusée depuis status={$locked->status}");
                return null;
            }

            $locked->update([
                'status'      => 'electing_mayor',
                'started_at'  => now(),
                'timers'      => TimerCalculator::forPlayerCount($locked->max_players),
            ]);

            // Distribuer les rôles et persister
            $players     = $locked->players()->get();
            $assignments = $this->roleDistributor->distribute($players, $locked);

            foreach ($assignments as $playerId => $role) {
                GamePlayer::where('id', $playerId)->update(['role' => $role]);
            }

            WaitForReadyPlayers::dispatch($locked->id)->delay(
                now()->addSeconds($locked->timer('ready_timeout'))
            );

            return ['game' => $locked, 'players' => $locked->players()->with('user')->get()];
        });

        if (! $data) {
            return;
        }

        // Broadcast public — liste sans rôles
        broadcast(new GameStarted($data['game']));

        // Broadcast privé par joueur — rôle + alliés loups si applicable
        foreach ($data['players'] as $player) {
            $allies = null;
            if ($player->isWerewolf()) {
                $allies = $data['players']
                    ->filter(fn (GamePlayer $p) => $p->id !== $player->id && $p->isWerewolf())
                    ->map(fn (GamePlayer $p) => ['id' => $p->id, 'pseudo' => $p->pseudo])
                    ->values()
                    ->toArray();
            }

            broadcast(new GameStarted($data['game'], $player, $allies));

            try {
                $player->user->notify(new RoleAssignedNotification($player->role));
            } catch (\Throwable) {}
        }
    }

    /**
     * Marque un joueur comme prêt et lance l'élection du maire si tous les joueurs le sont.
     * Guard atomique : no-op si la partie n'est plus en 'electing_mayor' ou si le joueur est déjà prêt.
     *
     * @param  GamePlayer $player Le joueur qui se déclare prêt
     * @return void
     */
    public function markReady(GamePlayer $player): void
    {
        $result = DB::transaction(function () use ($player) {
            $game = Game::where('id', $player->game_id)
                ->where('status', 'electing_mayor')
                ->lockForUpdate()
                ->first();

            if (! $game || $player->is_ready) {
                return null;
            }

            $player->update(['is_ready' => true]);

            $total      = $game->players()->count();
            $readyCount = $game->players()->where('is_ready', true)->count();

            $startElection = false;
            if ($readyCount === $total && $game->phase_deadline === null) {
                $timer = $game->timer('mayor_election');
                $game->update(['phase_deadline' => now()->addSeconds($timer)]);
                // delay > 0 : autorisé dans la transaction (Guard #5)
                ProcessMayorElection::dispatch($game->id)->delay(now()->addSeconds($timer));
                $startElection = true;
            }

            return compact('game', 'readyCount', 'total', 'startElection');
        });

        if (! $result) {
            return;
        }

        broadcast(new PlayerReady($result['game'], $result['readyCount'], $result['total']));

        if ($result['startElection']) {
            broadcast(new PhaseAnnouncement($result['game']->id, 'mayor_election', 'Élection du Maire. Que la sagesse guide vos votes !', 5000));
            broadcast(new MayorElectionStarted($result['game']));
        }
    }

    /**
     * Exclut un joueur de la salle d'attente (phase 'waiting' uniquement).
     * Crée un enregistrement Exclusion permanent pour bloquer toute ré-inscription.
     *
     * @param  GamePlayer $host   Le host qui exclut (is_host = true requis)
     * @param  GamePlayer $target Le joueur à exclure (doit être différent du host)
     * @param  string     $reason Motif affiché au joueur exclu via notification
     * @return void
     * @throws \Symfony\Component\HttpKernel\Exception\ConflictHttpException     (409) si la partie n'est plus en 'waiting'
     * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException (403) si $host n'est pas host, ou tente de s'exclure lui-même
     */
    public function excludePlayer(GamePlayer $host, GamePlayer $target, string $reason): void
    {
        $game = $host->game;

        if ($game->status !== 'waiting') {
            abort(409, 'Impossible d\'exclure un joueur après le début de la partie.');
        }

        if (! $host->is_host) {
            abort(403, 'Seul le host peut exclure un joueur.');
        }

        if ($host->id === $target->id) {
            abort(403, 'Le host ne peut pas s\'exclure lui-même.');
        }

        // Capturer avant le delete : $target->user reste en mémoire PHP même après delete()
        $targetUser = $target->user;

        DB::transaction(function () use ($game, $target, $reason, $targetUser) {
            Exclusion::create([
                'game_id'     => $game->id,
                'user_id'     => $targetUser->id,
                'player_id'   => $target->id,
                'reason'      => $reason,
                'excluded_at' => now(),
            ]);

            $target->delete();
        });

        // Broadcast public (pseudo seulement) puis privé (avec motif)
        // $target reste en mémoire PHP avec ses attributs après delete()
        broadcast(new PlayerExcluded($game, $target));
        broadcast(new PlayerExcluded($game, $target, $reason));

        try {
            $targetUser->notify(new PlayerExcludedNotification($reason));
        } catch (\Throwable) {}
    }

    /**
     * Enregistre l'inspection de la voyante sur une cible et retourne le joueur inspecté.
     * Guard atomique : impossible d'agir deux fois dans le même round.
     * Le rôle du joueur inspecté est lu par le contrôleur pour broadcaster SeerResult.
     *
     * @param  GamePlayer $seer     La voyante (rôle 'seer' requis)
     * @param  int        $targetId ID du joueur à inspecter (ne peut pas être la voyante elle-même)
     * @return GamePlayer            Le joueur inspecté
     * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException (403) si $seer n'est pas voyante
     * @throws \Symfony\Component\HttpKernel\Exception\ConflictHttpException     (409) si la phase n'est pas 'night' ou si l'action a déjà été posée ce round
     */
    public function seerCheck(GamePlayer $seer, int $targetId): GamePlayer
    {
        return app(\App\Services\RoleActions\SeerAction::class)->check($seer, $targetId);
    }

    /**
     * Le maire mort désigne manuellement son successeur (phase jour uniquement).
     * Déclenche startNight() après la succession (transition nuit → round suivant).
     * Guard atomique : impossible si la succession a déjà été effectuée ce round.
     *
     * @param  GamePlayer $mayor    Le maire éliminé (is_mayor = true, is_alive = false)
     * @param  int        $targetId ID du joueur vivant désigné successeur
     * @return GamePlayer            Le nouveau maire
     * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException (403) si $mayor n'est pas le maire éliminé
     * @throws \Symfony\Component\HttpKernel\Exception\ConflictHttpException     (409) si la phase n'est pas 'day' ou si la succession a déjà été effectuée
     */
    public function mayorSuccessionByPlayer(GamePlayer $mayor, int $targetId): GamePlayer
    {
        $game = $mayor->game;

        if (! $mayor->is_mayor || $mayor->is_alive) {
            abort(403, 'Seul le maire éliminé peut désigner son successeur.');
        }

        if ($game->status !== 'day') {
            abort(409, 'La succession du maire n\'est disponible que pendant la phase jour.');
        }

        $target = DB::transaction(function () use ($mayor, $targetId, $game) {
            $alreadyDone = GameAction::where('game_id', $game->id)
                ->where('type', 'mayor_succession')
                ->where('round', $game->round)
                ->lockForUpdate()
                ->exists();

            if ($alreadyDone) {
                abort(409, 'La succession du maire a déjà été effectuée.');
            }

            $target = GamePlayer::where('id', $targetId)
                ->where('game_id', $game->id)
                ->where('is_alive', true)
                ->firstOrFail();

            $mayor->update(['is_mayor' => false]);
            $target->update(['is_mayor' => true]);

            GameAction::create([
                'game_id'          => $game->id,
                'player_id'        => $mayor->id,
                'type'             => 'mayor_succession',
                'target_player_id' => $target->id,
                'round'            => $game->round,
                'phase'            => 'day',
            ]);

            return $target;
        });

        $this->phaseManager->startNight($game);

        return $target;
    }

    /**
     * Gère la déconnexion d'un joueur : génère un token, broadcaste PlayerDisconnected
     * et dispatche CheckReconnectionTimeout avec le délai du timer 'reconnection'.
     * No-op si un token de déconnexion existe déjà en cache (job déjà en attente).
     *
     * @param  GamePlayer $player Le joueur qui vient de se déconnecter
     * @return void
     */
    public function handleDisconnection(GamePlayer $player): void
    {
        $cacheKey = "player_disconnected.{$player->id}";
        $timer    = $player->game->timer('reconnection');

        // Si un token existe déjà, un job est déjà en attente → ne pas re-dispatcher
        if (Cache::has($cacheKey)) {
            return;
        }

        $token = Str::uuid()->toString();

        // Stocker le token 2× le timer pour couvrir les retards de queue
        Cache::put($cacheKey, $token, now()->addSeconds($timer * 2));

        broadcast(PlayerDisconnected::fromPlayer($player, $timer));

        CheckReconnectionTimeout::dispatch($player->id, $token)
            ->delay(now()->addSeconds($timer));
    }

    /**
     * Gère la reconnexion d'un joueur : invalide le token de déconnexion en cache
     * (CheckReconnectionTimeout détecte la divergence et s'arrête), remet is_inactive à false
     * et broadcaste PlayerReconnected.
     *
     * @param  GamePlayer $player Le joueur qui vient de se reconnecter
     * @return void
     */
    public function handleReconnection(GamePlayer $player): void
    {
        // Invalider le token → le Job en attente détectera la reconnexion et s'arrêtera
        Cache::forget("player_disconnected.{$player->id}");

        $player->update(['is_inactive' => false]);

        broadcast(PlayerReconnected::fromPlayer($player));
    }

    /**
     * Marque un joueur comme mort et inactif suite à un départ volontaire en cours de partie.
     * À distinguer du départ depuis la salle d'attente (DELETE game_players, jamais via cette méthode).
     *
     * @param  Game       $game   La partie en cours
     * @param  GamePlayer $player Le joueur qui quitte
     * @return void
     */
    public function quitGame(Game $game, GamePlayer $player): void
    {
        DB::transaction(function () use ($player) {
            $player->update(['is_alive' => false, 'is_inactive' => true]);
        });
        broadcast(new PlayerEliminated($game, $player, 'quit'));
    }

    /**
     * Valide un tableau de timers contre les limites configurées dans config/game.php (timers.limits).
     * Vérifie que chaque clé est configurable (host_configurable = true) et dans la plage min/max.
     *
     * @param  array<string, int> $timers Tableau [nom_timer => secondes] à valider
     * @return void
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException (422) si un timer est non configurable ou hors plage
     */
    public function validateTimerSettings(array $timers): void
    {
        app(\App\Services\GameSettingsService::class)->validateTimerSettings($timers);
    }

    /**
     * Fusionne les nouveaux timers dans game->settings['timers'] et persiste.
     * TimerCalculator::get() lit settings['timers'] en priorité sur config() pour toutes les clés configurables.
     *
     * @param  Game               $game   La partie concernée (statut 'waiting' requis)
     * @param  array<string, int> $timers Tableau [nom_timer => secondes] déjà validé
     * @return Game                        La partie avec settings mis à jour
     * @throws \Symfony\Component\HttpKernel\Exception\ConflictHttpException (409) si la partie n'est plus en 'waiting'
     */
    public function updateTimerSettings(Game $game, array $timers): Game
    {
        return app(\App\Services\GameSettingsService::class)->updateTimerSettings($game, $timers);
    }

    /**
     * Valide un tableau de rôles : seuls 'witch' et 'hunter' sont configurables, chacun valant 0 ou 1.
     *
     * @param  array<string, int> $roles Tableau [nom_role => 0|1] à valider
     * @return void
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException (422) si un rôle est inconnu ou a une valeur invalide
     */
    public function validateRoleSettings(array $roles): void
    {
        app(\App\Services\GameSettingsService::class)->validateRoleSettings($roles);
    }

    /**
     * Fusionne la composition de rôles dans game->settings['roles'] et persiste.
     * RoleDistributor lit settings['roles'] en priorité sur config/game.php au démarrage.
     *
     * @param  Game               $game  La partie concernée (statut 'waiting' requis)
     * @param  array<string, int> $roles Tableau [nom_role => 0|1] déjà validé
     * @return Game                       La partie avec settings mis à jour
     * @throws \Symfony\Component\HttpKernel\Exception\ConflictHttpException (409) si la partie n'est plus en 'waiting'
     */
    public function updateRoleSettings(Game $game, array $roles): Game
    {
        return app(\App\Services\GameSettingsService::class)->updateRoleSettings($game, $roles);
    }

    /**
     * Action de la Sorcière : sauver la victime des loups, empoisonner un joueur, ou passer son tour.
     * Guard atomique : impossible d'agir deux fois dans le même round (witch_heal/witch_kill/witch_pass).
     * La sorcière ne peut pas s'auto-sauver (action 'heal' refusée si la victime est la sorcière elle-même).
     * Si la cible d'un 'kill' est le chasseur, un enregistrement 'hunter_pending' est créé en DB.
     *
     * @param  GamePlayer  $witch    La sorcière (rôle 'witch' requis, doit être vivante)
     * @param  string      $action   Action choisie : 'heal', 'kill' ou 'pass'
     * @param  int|null    $targetId ID du joueur cible (requis pour 'heal' et 'kill', null pour 'pass')
     * @return array{action: string, target: ?GamePlayer} Action effectuée et joueur cible le cas échéant
     * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException (403) si $witch n'est pas la sorcière, est morte, ou s'auto-sauve
     * @throws \Symfony\Component\HttpKernel\Exception\ConflictHttpException     (409) si hors phase nuit, ou action déjà posée ce round
     */
    public function witchAct(GamePlayer $witch, string $action, ?int $targetId): array
    {
        return app(\App\Services\RoleActions\WitchAction::class)->act($witch, $action, $targetId);
    }

    /**
     * Tir du Chasseur : élimine une cible après la mort du chasseur (nuit ou jour).
     * Guard atomique : impossible de tirer deux fois dans le même round.
     * La phase du GameAction est déterminée selon le statut courant (night/processing_night → 'night', sinon 'day').
     *
     * @param  GamePlayer $hunter   Le chasseur éliminé (rôle 'hunter' requis, doit être mort)
     * @param  int        $targetId ID du joueur vivant à éliminer (ne peut pas être le chasseur lui-même)
     * @return GamePlayer            Le joueur éliminé par le tir
     * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException (403) si $hunter n'est pas le chasseur ou est encore vivant
     * @throws \Symfony\Component\HttpKernel\Exception\ConflictHttpException     (409) si phase invalide ou tir déjà effectué ce round
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException     (404) si la cible est invalide ou déjà morte
     */
    public function hunterShoot(GamePlayer $hunter, int $targetId): GamePlayer
    {
        return app(\App\Services\RoleActions\HunterAction::class)->shoot($hunter, $targetId);
    }

    /**
     * Annule une partie en cours : passe le statut à 'finished' avec winner_team = null.
     * Guard atomique : no-op si la partie est déjà terminée ou encore en 'waiting'.
     * Déclenché quand plus de 50% des joueurs sont inactifs (winner_team = null → rôles non révélés).
     *
     * @param  Game $game La partie à annuler
     * @return void
     */
    public function cancelGame(Game $game): void
    {
        $data = DB::transaction(function () use ($game) {
            $locked = Game::where('id', $game->id)
                ->whereNotIn('status', ['finished', 'waiting'])
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return null;
            }

            $locked->update([
                'status'      => 'finished',
                'winner_team' => null,
                'finished_at' => now(),
            ]);

            return ['game' => $locked, 'players' => $locked->players()->get()];
        });

        if ($data) {
            broadcast(new PhaseAnnouncement($data['game']->id, 'game_cancelled', 'Partie annulée (trop d\'inactifs).', 6000));
            broadcast(new GameFinished($data['game'], $data['players'], null));
        }
    }

    /**
     * Génère un code de partie unique sur 6 caractères alphanumériques majuscules.
     * Réessaie jusqu'à trouver un code absent de la table games.
     *
     * @return string Code unique en majuscules (ex. 'XKZP9A')
     */
    private function generateUniqueCode(): string
    {
        do {
            $code = strtoupper(Str::random(6));
        } while (Game::where('code', $code)->exists());

        return $code;
    }
}