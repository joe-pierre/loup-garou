<?php

namespace App\Services;

use App\Events\Game\GameFinished;
use App\Events\Game\GameStarted;
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

class GameService
{
    public function __construct(
        private RoleDistributor $roleDistributor,
        private PhaseManager $phaseManager,
    ) {}

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

    public function joinGame(User $user, string $code, string $pseudo): GamePlayer
    {
        return DB::transaction(function () use ($user, $code, $pseudo) {
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
                return $existing;
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

            broadcast(new PlayerJoined($game, $player));

            $currentCount = $game->players()->count();
            if ($currentCount === $game->max_players) {
                $this->startGame($game);
            }

            return $player;
        });
    }

    public function startGame(Game $game): void
    {
        DB::transaction(function () use ($game) {
            // Re-lock et vérifier le status pour éviter un double démarrage
            $locked = Game::where('id', $game->id)
                ->where('status', 'waiting')
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return;
            }

            if (! $locked->canTransition('start_election')) {
                Log::warning("Transition 'start_election' refusée depuis status={$locked->status}");
                return;
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

            // Recharger avec les rôles assignés (user eager-loadé pour les notifications)
            $players = $locked->players()->with('user')->get();

            // Broadcast public — liste sans rôles
            broadcast(new GameStarted($locked));

            // Broadcast privé par joueur — rôle + alliés loups si applicable
            foreach ($players as $player) {
                $allies = null;
                if ($player->isWerewolf()) {
                    $allies = $players
                        ->filter(fn (GamePlayer $p) => $p->id !== $player->id && $p->isWerewolf())
                        ->map(fn (GamePlayer $p) => ['id' => $p->id, 'pseudo' => $p->pseudo])
                        ->values()
                        ->toArray();
                }

                broadcast(new GameStarted($locked, $player, $allies));

                try {
                    $player->user->notify(new RoleAssignedNotification($player->role));
                } catch (\Throwable) {}
            }

            WaitForReadyPlayers::dispatch($locked->id)->delay(
                now()->addSeconds(config('game.timers.ready_timeout', 60))
            );
        });
    }

    public function markReady(GamePlayer $player): void
    {
        DB::transaction(function () use ($player) {
            $game = Game::where('id', $player->game_id)
                ->where('status', 'electing_mayor')
                ->lockForUpdate()
                ->first();

            if (! $game || $player->is_ready) {
                return;
            }

            $player->update(['is_ready' => true]);

            $total      = $game->players()->count();
            $readyCount = $game->players()->where('is_ready', true)->count();

            broadcast(new PlayerReady($game, $readyCount, $total));

            // Déclencher l'élection maire si tous prêts et pas encore déclenchée
            if ($readyCount === $total && $game->phase_deadline === null) {
                $timer    = config('game.timers.mayor_election', 30);
                $deadline = now()->addSeconds($timer);
                $game->update(['phase_deadline' => $deadline]);
                broadcast(new MayorElectionStarted($game));
                ProcessMayorElection::dispatch($game->id)->delay(now()->addSeconds($timer));
            }
        });
    }

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

        DB::transaction(function () use ($game, $target, $reason) {
            $targetUser = $target->user;

            Exclusion::create([
                'game_id'     => $game->id,
                'user_id'     => $targetUser->id,
                'player_id'   => $target->id,
                'reason'      => $reason,
                'excluded_at' => now(),
            ]);

            $target->delete();

            // Broadcast public (pseudo seulement) puis privé (avec motif)
            // L'event stocke des scalaires : pas de dépendance DB après delete
            broadcast(new PlayerExcluded($game, $target));
            broadcast(new PlayerExcluded($game, $target, $reason));

            try {
                $targetUser->notify(new PlayerExcludedNotification($reason));
            } catch (\Throwable) {}
        });
    }

    public function seerCheck(GamePlayer $seer, int $targetId): GamePlayer
    {
        if ($seer->role !== 'seer') {
            abort(403, 'Seule la voyante peut utiliser ce pouvoir.');
        }

        $game = $seer->game;

        if ($game->status !== 'night') {
            abort(409, 'L\'action de la voyante n\'est pas disponible hors phase nuit.');
        }

        return DB::transaction(function () use ($seer, $targetId, $game) {
            $alreadyActed = GameAction::where('game_id', $game->id)
                ->where('player_id', $seer->id)
                ->where('type', 'seer_check')
                ->where('round', $game->round)
                ->lockForUpdate()
                ->exists();

            if ($alreadyActed) {
                abort(409, 'Vous avez déjà utilisé votre pouvoir ce round.');
            }

            GameAction::create([
                'game_id'          => $game->id,
                'player_id'        => $seer->id,
                'type'             => 'seer_check',
                'target_player_id' => $targetId,
                'round'            => $game->round,
                'phase'            => 'night',
            ]);

            return GamePlayer::findOrFail($targetId);
        });
    }

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

    public function handleDisconnection(GamePlayer $player): void
    {
        $cacheKey = "player_disconnected.{$player->id}";
        $timer    = config('game.timers.reconnection', 30);

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

    public function handleReconnection(GamePlayer $player): void
    {
        // Invalider le token → le Job en attente détectera la reconnexion et s'arrêtera
        Cache::forget("player_disconnected.{$player->id}");

        $player->update(['is_inactive' => false]);

        broadcast(PlayerReconnected::fromPlayer($player));
    }

    public function quitGame(Game $game, GamePlayer $player): void
    {
        DB::transaction(function () use ($player) {
            $player->update(['is_alive' => false, 'is_inactive' => true]);
        });
        broadcast(new PlayerEliminated($game, $player, 'quit'));
    }

    public function validateTimerSettings(array $timers): void
    {
        $limits = config('game.timers.limits');

        foreach ($timers as $key => $value) {
            if (! isset($limits[$key]) || $limits[$key]['host_configurable'] === false) {
                abort(422, "Le timer '{$key}' n'est pas configurable.");
            }

            if (! is_int($value) || $value < $limits[$key]['min'] || $value > $limits[$key]['max']) {
                abort(422, "Le timer '{$key}' doit être compris entre {$limits[$key]['min']} et {$limits[$key]['max']} secondes.");
            }
        }
    }

    public function updateTimerSettings(Game $game, array $timers): Game
    {
        if ($game->status !== 'waiting') {
            abort(409, 'Les timers ne peuvent être modifiés que dans la salle d\'attente.');
        }

        $this->validateTimerSettings($timers);

        $currentSettings = $game->settings ?? [];
        $currentSettings['timers'] = array_merge($currentSettings['timers'] ?? [], $timers);

        $game->update(['settings' => $currentSettings]);

        return $game;
    }

    public function validateRoleSettings(array $roles): void
    {
        foreach (['witch', 'hunter'] as $key) {
            if (array_key_exists($key, $roles) && ! in_array($roles[$key], [0, 1], true)) {
                abort(422, "Le rôle '{$key}' doit être 0 ou 1.");
            }
        }

        foreach (array_keys($roles) as $key) {
            if (! in_array($key, ['witch', 'hunter'], true)) {
                abort(422, "Le rôle '{$key}' n'est pas configurable.");
            }
        }
    }

    public function updateRoleSettings(Game $game, array $roles): Game
    {
        if ($game->status !== 'waiting') {
            abort(409, 'La composition des rôles ne peut être modifiée que dans la salle d\'attente.');
        }

        $this->validateRoleSettings($roles);

        $currentSettings = $game->settings ?? [];
        $currentSettings['roles'] = array_merge($currentSettings['roles'] ?? [], $roles);

        $game->update(['settings' => $currentSettings]);

        return $game;
    }

    /**
     * Action de la Sorcière : sauver la victime des loups, empoisonner un joueur, ou ne rien faire.
     *
     * @return array{action: string, target: ?GamePlayer}
     */
    public function witchAct(GamePlayer $witch, string $action, ?int $targetId): array
    {
        if (! $witch->isWitch()) {
            abort(403, 'Seule la sorcière peut utiliser ce pouvoir.');
        }

        if (! $witch->is_alive) {
            abort(403, 'Un joueur éliminé ne peut pas agir.');
        }

        $game = $witch->game;

        if (! in_array($game->status, ['night', 'processing_night'])) {
            abort(409, 'L\'action de la sorcière n\'est pas disponible hors phase nuit.');
        }

        $result = DB::transaction(function () use ($witch, $action, $targetId, $game) {
            $alreadyActed = GameAction::where('game_id', $game->id)
                ->where('player_id', $witch->id)
                ->where('round', $game->round)
                ->whereIn('type', ['witch_heal', 'witch_kill', 'witch_pass'])
                ->lockForUpdate()
                ->exists();

            if ($alreadyActed) {
                abort(409, 'Vous avez déjà utilisé votre pouvoir ce round.');
            }

            $settings = $witch->settings ?? [];
            $target   = null;

            if ($action === 'heal') {
                $victim = app(VoteService::class)->resolveNightVote($game);

                if (! $victim || $victim->id === $witch->id) {
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

                $target->update(['is_alive' => false]);
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
            } else {
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

        // Hors transaction : gestion du cache "le chasseur doit tirer" (Guard #2)
        if ($action === 'heal' && $result['target']?->isHunter()) {
            Cache::forget("hunter_must_shoot_{$game->id}");
        }

        if ($action === 'kill' && $result['target']?->isHunter()) {
            Cache::put("hunter_must_shoot_{$game->id}", $result['target']->id, now()->addMinutes(10));
        }

        return $result;
    }

    /**
     * Tir du Chasseur, après sa mort (nuit ou jour).
     */
    public function hunterShoot(GamePlayer $hunter, int $targetId): GamePlayer
    {
        if (! $hunter->isHunter()) {
            abort(403, 'Seul le chasseur peut utiliser ce pouvoir.');
        }

        if ($hunter->is_alive) {
            abort(403, 'Le chasseur ne peut tirer qu\'après sa mort.');
        }

        $game = $hunter->game;

        if (! in_array($game->status, ['night', 'processing_night', 'day', 'processing_day'])) {
            abort(409, 'Le tir du chasseur n\'est pas disponible dans cette phase.');
        }

        if ($targetId === $hunter->id) {
            abort(403, 'Le chasseur ne peut pas se tirer lui-même.');
        }

        return DB::transaction(function () use ($hunter, $targetId, $game) {
            $alreadyShot = GameAction::where('game_id', $game->id)
                ->where('player_id', $hunter->id)
                ->where('type', 'hunter_shot')
                ->where('round', $game->round)
                ->lockForUpdate()
                ->exists();

            if ($alreadyShot) {
                abort(409, 'Vous avez déjà tiré ce round.');
            }

            $target = GamePlayer::where('id', $targetId)
                ->where('game_id', $game->id)
                ->where('is_alive', true)
                ->lockForUpdate()
                ->first();

            if (! $target) {
                abort(404, 'Cible invalide.');
            }

            $target->update(['is_alive' => false]);

            GameAction::create([
                'game_id'          => $game->id,
                'player_id'        => $hunter->id,
                'type'             => 'hunter_shot',
                'target_player_id' => $target->id,
                'round'            => $game->round,
                'phase'            => in_array($game->status, ['night', 'processing_night']) ? 'night' : 'day',
            ]);

            return $target;
        });
    }

    public function cancelGame(Game $game): void
    {
        DB::transaction(function () use ($game) {
            $locked = Game::where('id', $game->id)
                ->whereNotIn('status', ['finished', 'waiting'])
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return;
            }

            $locked->update([
                'status'      => 'finished',
                'winner_team' => null,
                'finished_at' => now(),
            ]);

            $players = $locked->players()->get();

            broadcast(new GameFinished($locked, $players, null));
        });
    }

    private function generateUniqueCode(): string
    {
        do {
            $code = strtoupper(Str::random(6));
        } while (Game::where('code', $code)->exists());

        return $code;
    }
}