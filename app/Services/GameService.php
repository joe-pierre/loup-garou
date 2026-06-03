<?php

namespace App\Services;

use App\Events\Game\GameStarted;
use App\Events\Game\MayorElectionStarted;
use App\Events\Game\PlayerExcluded;
use App\Events\Game\PlayerJoined;
use App\Events\Game\PlayerReady;
use App\Jobs\ProcessMayorElection;
use App\Jobs\WaitForReadyPlayers;
use App\Models\Exclusion;
use App\Models\Game;
use App\Models\GameAction;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GameService
{
    public function __construct(private RoleDistributor $roleDistributor) {}

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
                ->whereHas('player', fn ($q) => $q->where('user_id', $user->id))
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

            $locked->update(['status' => 'electing_mayor', 'started_at' => now()]);

            // Distribuer les rôles et persister
            $players     = $locked->players()->get();
            $assignments = $this->roleDistributor->distribute($players);

            foreach ($assignments as $playerId => $role) {
                GamePlayer::where('id', $playerId)->update(['role' => $role]);
            }

            // Recharger avec les rôles assignés
            $players = $locked->players()->get();

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
            // Insérer dans exclusions avant suppression (FK) — $target reste en mémoire après delete()
            Exclusion::create([
                'game_id'     => $game->id,
                'player_id'   => $target->id,
                'reason'      => $reason,
                'excluded_at' => now(),
            ]);

            $target->delete();

            // Broadcast public (pseudo seulement) puis privé (avec motif)
            // L'event stocke des scalaires : pas de dépendance DB après delete
            broadcast(new PlayerExcluded($game, $target));
            broadcast(new PlayerExcluded($game, $target, $reason));
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

    private function generateUniqueCode(): string
    {
        do {
            $code = strtoupper(Str::random(6));
        } while (Game::where('code', $code)->exists());

        return $code;
    }
}
